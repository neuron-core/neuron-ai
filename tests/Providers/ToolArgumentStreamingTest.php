<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use NeuronAI\Tests\Stubs\Tools\ToolStub;
use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function implode;

class ToolArgumentStreamingTest extends TestCase
{
    private function guzzle(string $streamBody): GuzzleHttpClient
    {
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $streamBody),
        ]);

        return new GuzzleHttpClient(handler: HandlerStack::create($mockHandler));
    }

    /**
     * @return array{0: object[], 1: \NeuronAI\Chat\Messages\Message}
     */
    private function consume(Generator $generator): array
    {
        $chunks = [];
        foreach ($generator as $chunk) {
            $chunks[] = $chunk;
        }

        return [$chunks, $generator->getReturn()->message()];
    }

    /**
     * @param object[] $chunks
     * @return ToolArgumentChunk[]
     */
    private function argumentChunks(array $chunks): array
    {
        return array_values(array_filter($chunks, fn (object $chunk): bool => $chunk instanceof ToolArgumentChunk));
    }

    public function test_openai_stream_yields_tool_argument_chunks(): void
    {
        $streamBody = 'data: {"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_123","type":"function","function":{"name":"tool","arguments":""}}]},"finish_reason":null}]}' . "\n\n";
        $streamBody .= 'data: {"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"city\":"}}]},"finish_reason":null}]}' . "\n\n";
        $streamBody .= 'data: {"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"Rome\"}"}}]},"finish_reason":null}]}' . "\n\n";
        $streamBody .= 'data: {"id":"chatcmpl-123","object":"chat.completion.chunk","choices":[{"index":0,"delta":{},"finish_reason":"tool_calls"}]}' . "\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $provider = (new OpenAI('', 'gpt-4o'))
            ->setTools([new ToolStub('tool', description: 'description')])
            ->setHttpClient($this->guzzle($streamBody));

        [$chunks, $message] = $this->consume($provider->stream(new UserMessage('Hi')));

        $argumentChunks = $this->argumentChunks($chunks);
        $this->assertCount(2, $argumentChunks);
        $this->assertSame(['{"city":', '"Rome"}'], array_map(fn (ToolArgumentChunk $chunk): string => $chunk->delta, $argumentChunks));
        $this->assertSame('tool', $argumentChunks[0]->toolName);
        $this->assertSame('call_123', $argumentChunks[0]->toolCallId);
        $this->assertSame('{"city":"Rome"}', implode('', array_map(fn (ToolArgumentChunk $chunk): string => $chunk->delta, $argumentChunks)));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_mistral_stream_yields_tool_argument_chunks_without_duplicates_on_finish(): void
    {
        // Mistral sends the last argument fragment and finish_reason in the same chunk.
        $streamBody = 'data: {"id":"cmpl-1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"role":"assistant","tool_calls":[{"index":0,"id":"call_9","function":{"name":"tool","arguments":""}}]},"finish_reason":null}]}' . "\n\n";
        $streamBody .= 'data: {"id":"cmpl-1","object":"chat.completion.chunk","choices":[{"index":0,"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"city\": \"Rome\"}"}}]},"finish_reason":"tool_calls"}]}' . "\n\n";
        $streamBody .= "data: [DONE]\n\n";

        $provider = (new Mistral('', 'mistral-large-latest'))
            ->setTools([new ToolStub('tool', description: 'description')])
            ->setHttpClient($this->guzzle($streamBody));

        [$chunks, $message] = $this->consume($provider->stream(new UserMessage('Hi')));

        $argumentChunks = $this->argumentChunks($chunks);
        $this->assertCount(1, $argumentChunks);
        $this->assertSame('{"city": "Rome"}', $argumentChunks[0]->delta);
        $this->assertSame('tool', $argumentChunks[0]->toolName);
        $this->assertSame('call_9', $argumentChunks[0]->toolCallId);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_anthropic_stream_yields_tool_argument_chunks(): void
    {
        $streamBody = "event: message_start\n";
        $streamBody .= 'data: {"type":"message_start","message":{"id":"msg_123","usage":{"input_tokens":10,"output_tokens":0}}}' . "\n\n";
        $streamBody .= "event: content_block_start\n";
        $streamBody .= 'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"toolu_123","name":"tool","input":{}}}' . "\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"city\":"}}' . "\n\n";
        $streamBody .= "event: content_block_delta\n";
        $streamBody .= 'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"\"Rome\"}"}}' . "\n\n";
        $streamBody .= "event: content_block_stop\n";
        $streamBody .= 'data: {"type":"content_block_stop","index":0}' . "\n\n";
        $streamBody .= "event: message_delta\n";
        $streamBody .= 'data: {"type":"message_delta","delta":{"stop_reason":"tool_use"},"usage":{"output_tokens":5}}' . "\n\n";

        $provider = (new Anthropic('', 'claude-3-7-sonnet-latest'))
            ->setTools([new ToolStub('tool', description: 'description')])
            ->setHttpClient($this->guzzle($streamBody));

        [$chunks, $message] = $this->consume($provider->stream(new UserMessage('Hi')));

        $argumentChunks = $this->argumentChunks($chunks);
        $this->assertCount(2, $argumentChunks);
        $this->assertSame(['{"city":', '"Rome"}'], array_map(fn (ToolArgumentChunk $chunk): string => $chunk->delta, $argumentChunks));
        $this->assertSame('tool', $argumentChunks[0]->toolName);
        $this->assertSame('toolu_123', $argumentChunks[0]->toolCallId);
        $this->assertSame('msg_123', $argumentChunks[0]->messageId);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_cohere_stream_yields_tool_argument_chunks(): void
    {
        $streamBody = 'data: {"type":"message-start","id":"msg_1"}' . "\n\n";
        $streamBody .= 'data: {"type":"tool-plan-delta","delta":{"message":{"tool_plan":"I will call the tool"}}}' . "\n\n";
        $streamBody .= 'data: {"type":"tool-call-start","index":0,"delta":{"message":{"tool_calls":{"id":"call_7","type":"function","function":{"name":"tool","arguments":""}}}}}' . "\n\n";
        $streamBody .= 'data: {"type":"tool-call-delta","index":0,"delta":{"message":{"tool_calls":{"function":{"arguments":"{\"city\":"}}}}}' . "\n\n";
        $streamBody .= 'data: {"type":"tool-call-delta","index":0,"delta":{"message":{"tool_calls":{"function":{"arguments":"\"Rome\"}"}}}}}' . "\n\n";
        $streamBody .= 'data: {"type":"tool-call-end","index":0}' . "\n\n";

        $provider = (new Cohere('', 'command-r'))
            ->setTools([new ToolStub('tool', description: 'description')])
            ->setHttpClient($this->guzzle($streamBody));

        [$chunks, $message] = $this->consume($provider->stream(new UserMessage('Hi')));

        $argumentChunks = $this->argumentChunks($chunks);
        $this->assertCount(2, $argumentChunks);
        $this->assertSame(['{"city":', '"Rome"}'], array_map(fn (ToolArgumentChunk $chunk): string => $chunk->delta, $argumentChunks));
        $this->assertSame('tool', $argumentChunks[0]->toolName);
        $this->assertSame('call_7', $argumentChunks[0]->toolCallId);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_openai_responses_stream_yields_tool_argument_chunks(): void
    {
        $streamBody = 'data: {"type":"response.output_item.added","output_index":0,"item":{"type":"function_call","id":"fc_1","call_id":"call_5","name":"tool","arguments":""}}' . "\n\n";
        $streamBody .= 'data: {"type":"response.function_call_arguments.delta","item_id":"fc_1","delta":"{\"city\":"}' . "\n\n";
        $streamBody .= 'data: {"type":"response.function_call_arguments.delta","item_id":"fc_1","delta":"\"Rome\"}"}' . "\n\n";
        $streamBody .= 'data: {"type":"response.function_call_arguments.done","item_id":"fc_1","arguments":"{\"city\":\"Rome\"}"}' . "\n\n";
        $streamBody .= 'data: {"type":"response.completed","response":{"usage":{"input_tokens":10,"output_tokens":5}}}' . "\n\n";

        $provider = (new OpenAIResponses('', 'gpt-4o'))
            ->setTools([new ToolStub('tool', description: 'description')])
            ->setHttpClient($this->guzzle($streamBody));

        [$chunks, $message] = $this->consume($provider->stream(new UserMessage('Hi')));

        $argumentChunks = $this->argumentChunks($chunks);
        $this->assertCount(2, $argumentChunks);
        $this->assertSame(['{"city":', '"Rome"}'], array_map(fn (ToolArgumentChunk $chunk): string => $chunk->delta, $argumentChunks));
        $this->assertSame('tool', $argumentChunks[0]->toolName);
        $this->assertSame('call_5', $argumentChunks[0]->toolCallId);
        $this->assertSame('fc_1', $argumentChunks[0]->messageId);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }
}
