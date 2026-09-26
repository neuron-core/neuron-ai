<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Cohere;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Cohere\MessageMapper;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class CohereTest extends TestCase
{
    use RecordsHttpRequests;
    use ConsumesProviderStreams;

    protected const SECRET = 'co-SECRET-0123456789';

    protected function provider(string ...$responses): Cohere
    {
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = new Response(200, body: $response);
        }

        $provider = new Cohere(self::SECRET, 'command-a-03-2025', httpClient: $this->recordingClient(...$queue));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    /**
     * @param array<int, array<string, mixed>> $content
     */
    protected static function answer(array $content, string $finishReason = 'COMPLETE'): string
    {
        return json_encode([
            'id' => 'r1',
            'finish_reason' => $finishReason,
            'message' => ['role' => 'assistant', 'content' => $content],
            'usage' => ['billed_units' => ['input_tokens' => 1], 'tokens' => ['input_tokens' => 14, 'output_tokens' => 5]],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sentBody(int $index = 0): array
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_chat_targets_the_v2_chat_endpoint_with_bearer_authentication(): void
    {
        $provider = $this->provider(self::answer([['type' => 'text', 'text' => 'Hi']]));
        $provider->systemPrompt('Be brief');

        $provider->chat(new UserMessage('Hello'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(['POST https://api.cohere.ai/v2/chat'], $this->sentTargets());
        $this->assertSame('Bearer '.self::SECRET, $request->getHeaderLine('Authorization'));
        $this->assertStringNotContainsString(self::SECRET, (string) $request->getBody());
        $body = $this->sentBody();
        $this->assertSame('command-a-03-2025', $body['model']);
        $this->assertSame([
            ['role' => 'system', 'content' => [['type' => 'text', 'text' => 'Be brief']]],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
        ], $body['messages']);
        $this->assertSame('lookup', $body['tools'][0]['function']['name']);
    }

    public function test_answer_with_thinking_text_usage_and_finish_reason(): void
    {
        $message = $this->provider(self::answer([
            ['type' => 'thinking', 'thinking' => 'Reasoning'],
            ['type' => 'text', 'text' => 'Answer'],
            ['type' => 'citation-like-unknown'],
        ]))->chat(new UserMessage('Q'))->message();

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Reasoning', $message->getReasoning()?->content);
        $this->assertSame('Answer', $message->getContent());
        $this->assertCount(2, $message->getContentBlocks());
        $this->assertSame('COMPLETE', $message->stopReason());
        $this->assertSame([14, 5], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_tool_call_answer_becomes_tool_call_message(): void
    {
        $body = json_encode([
            'finish_reason' => 'TOOL_CALL',
            'message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'I will look it up']],
                'tool_calls' => [['id' => 'lookup_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"q":"rome"}']]],
            ],
        ], JSON_THROW_ON_ERROR);

        $message = $this->provider($body)->chat(new UserMessage('Where?'))->message();

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('I will look it up', $message->getContent());
        $this->assertSame('TOOL_CALL', $message->stopReason());
        [$call] = $message->getToolCalls();
        $this->assertSame(['lookup', 'lookup_1', ['q' => 'rome']], [$call->getName(), $call->getCallId(), $call->getInputs()]);
    }

    public function test_http_error_does_not_expose_the_api_key(): void
    {
        $provider = new Cohere(self::SECRET, 'command-a', httpClient: $this->recordingClient(
            new Response(401, body: '{"message":"invalid api token"}'),
        ));

        try {
            $provider->chat(new UserMessage('Hi'));
            $this->fail('A 401 response must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertStringContainsString('invalid api token', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }
    }

    public function test_structured_output_requests_a_json_object_with_the_schema_only_for_that_call(): void
    {
        $provider = $this->provider(self::answer([['type' => 'text', 'text' => '{"name":"Ada"}']]), self::answer([['type' => 'text', 'text' => 'plain']]));
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];

        $provider->structured(new UserMessage('Who?'), 'Person', $schema);
        $provider->chat(new UserMessage('Plain'));

        $this->assertSame(['type' => 'json_object', 'json_schema' => $schema], $this->sentBody(0)['response_format']);
        $this->assertArrayNotHasKey('response_format', $this->sentBody(1));
    }

    public function test_stream_request_drops_openai_stream_options(): void
    {
        $provider = $this->provider(self::sseBody([
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Hi']]]],
        ]));

        $this->consumeStream($provider->stream(new UserMessage('Hello')));

        $body = $this->sentBody();
        $this->assertSame(['POST https://api.cohere.ai/v2/chat'], $this->sentTargets());
        $this->assertTrue($body['stream']);
        $this->assertArrayNotHasKey('stream_options', $body);
    }

    public function test_streamed_tool_plan_and_arguments_build_the_tool_call_message(): void
    {
        $provider = $this->provider(self::sseBody([
            ['type' => 'message-start', 'id' => 'm1'],
            ['type' => 'tool-plan-delta', 'delta' => ['message' => ['tool_plan' => 'I will ']]],
            ['type' => 'tool-plan-delta', 'delta' => ['message' => ['tool_plan' => 'search.']]],
            ['type' => 'tool-call-start', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['id' => 'lookup_1', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '']]]]],
            ['type' => 'tool-call-delta', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['function' => ['arguments' => '{"q":']]]]],
            ['type' => 'tool-call-delta', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['function' => ['arguments' => '"rome"}']]]]],
            ['type' => 'tool-call-end', 'index' => 0],
        ]));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Where?')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('I will search.', $message->getContent());
        [$call] = $message->getToolCalls();
        $this->assertSame(['lookup', 'lookup_1', ['q' => 'rome']], [$call->getName(), $call->getCallId(), $call->getInputs()]);
        $deltas = [];
        foreach ($chunks as $chunk) {
            $this->assertInstanceOf(ToolArgumentChunk::class, $chunk);
            $deltas[] = $chunk->delta;
        }
        $this->assertSame(['{"q":', '"rome"}'], $deltas);
    }

    public function test_streamed_text_accumulates_by_content_index(): void
    {
        $provider = $this->provider(self::sseBody([
            ['type' => 'content-start', 'index' => 0, 'delta' => ['message' => ['content' => ['type' => 'text', 'text' => '']]]],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'Bon']]]],
            ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['text' => 'jour']]]],
            ['type' => 'content-end', 'index' => 0],
            ['type' => 'message-end', 'delta' => ['finish_reason' => 'COMPLETE'], 'usage' => ['tokens' => ['input_tokens' => 4, 'output_tokens' => 2]]],
        ]));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Bon', 'jour'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertSame('Bonjour', $message->getContent());
        $this->assertSame([4, 2], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_streamed_tool_call_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider(self::sseBody([
            ['type' => 'tool-call-start', 'index' => 0, 'delta' => ['message' => ['tool_calls' => ['id' => 'x', 'type' => 'function', 'function' => ['name' => 'drop_db', 'arguments' => '{}']]]]],
            ['type' => 'tool-call-end', 'index' => 0],
        ]));

        $stream = $provider->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: drop_db.');
        iterator_to_array($stream);
    }

    public function test_mapper_sends_reasoning_as_thinking_and_drops_files(): void
    {
        $mapped = (new MessageMapper())->map([
            new UserMessage([new TextContent('Read'), new FileContent('JVBERi0=', SourceType::BASE64, 'application/pdf', 'a.pdf')]),
            new AssistantMessage([new ReasoningContent('Thinking'), new TextContent('Done')]),
        ]);

        $this->assertSame([['type' => 'text', 'text' => 'Read']], $mapped[0]['content']);
        $this->assertSame([['type' => 'thinking', 'thinking' => 'Thinking'], ['type' => 'text', 'text' => 'Done']], $mapped[1]['content']);
    }

    public function test_mapper_sends_tool_plan_and_object_arguments(): void
    {
        $mapped = (new MessageMapper())->map([new ToolCallMessage('I will search', [
            ToolCall::make('lookup', 'lookup_1', ['q' => 'rome']),
            ToolCall::make('now', 'now_2'),
        ])]);

        $this->assertSame(
            '[{"role":"assistant","tool_plan":"I will search","tool_calls":['
            .'{"id":"lookup_1","type":"function","function":{"name":"lookup","arguments":"{\"q\":\"rome\"}"}},'
            .'{"id":"now_2","type":"function","function":{"name":"now","arguments":"{}"}}]}]',
            json_encode($mapped, JSON_THROW_ON_ERROR),
        );
    }
}
