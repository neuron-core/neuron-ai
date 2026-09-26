<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Mistral;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function iterator_to_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class MistralStreamTest extends TestCase
{
    use RecordsHttpRequests;
    use ConsumesProviderStreams;

    /**
     * @param array<string, mixed> $parameters
     */
    protected function provider(string $body, array $parameters = []): Mistral
    {
        $provider = new Mistral('key', 'mistral-small-latest', $parameters, httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    /**
     * @param array<string, mixed> $delta
     * @return array<string, mixed>
     */
    protected static function delta(array $delta, ?string $finishReason = null): array
    {
        return ['id' => 'x', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]]];
    }

    public function test_request_enables_streaming_with_usage_even_when_parameters_disable_it(): void
    {
        $provider = $this->provider(self::sseBody([self::delta(['content' => 'ok'], 'stop')])."data: [DONE]\n\n", [
            'stream_options' => ['include_usage' => false],
            'max_tokens' => 20,
        ]);
        $provider->systemPrompt('sys');

        $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['POST https://api.mistral.ai/v1/chat/completions'], $this->sentTargets());
        $this->assertTrue($body['stream']);
        $this->assertSame(['include_usage' => true], $body['stream_options']);
        $this->assertSame(20, $body['max_tokens']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame('lookup', $body['tools'][0]['function']['name']);
    }

    public function test_text_deltas_accumulate_with_usage_and_finish_reason(): void
    {
        $provider = $this->provider(self::sseBody([
            self::delta(['role' => 'assistant', 'content' => 'Bon']),
            self::delta(['content' => 'jour']),
            self::delta(['content' => ''], 'stop') + ['usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]],
        ])."data: [DONE]\n\n");

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Bon', 'jour'], array_values(array_filter($this->contentsOf(TextChunk::class, $chunks), static fn (string $text): bool => $text !== '')));
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Bonjour', $message->getContent());
        $this->assertSame('stop', $message->stopReason());
        $this->assertSame([5, 2], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
    }

    public function test_tool_call_arguments_across_chunks_build_the_tool_call_message(): void
    {
        $provider = $this->provider(self::sseBody([
            self::delta(['content' => 'Checking']),
            self::delta(['tool_calls' => [['id' => 'call_1', 'index' => 0, 'function' => ['name' => 'lookup', 'arguments' => '']]]]),
            self::delta(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"q":']]]]),
            self::delta(['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"rome"}']]]], 'tool_calls')
                + ['usage' => ['prompt_tokens' => 9, 'completion_tokens' => 3]],
        ]));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Where?')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking', $message->getContent());
        [$call] = $message->getToolCalls();
        $this->assertSame(['lookup', 'call_1', ['q' => 'rome']], [$call->getName(), $call->getCallId(), $call->getInputs()]);
        $this->assertSame([9, 3], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
        $arguments = [];
        foreach ($chunks as $chunk) {
            if ($chunk instanceof ToolArgumentChunk) {
                $this->assertSame(['lookup', 'call_1', $message->getId()], [$chunk->toolName, $chunk->toolCallId, $chunk->messageId]);
                $arguments[] = $chunk->delta;
            }
        }
        $this->assertSame(['{"q":', '"rome"}'], $arguments);
    }

    public function test_malformed_event_payload_raises_provider_exception(): void
    {
        $provider = $this->provider("data: {\"choices\":[{\"delta\":{\"content\":\"a\"}\n\n");

        $stream = $provider->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Streaming error - Syntax error');
        iterator_to_array($stream);
    }

    public function test_streamed_tool_call_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider(self::sseBody([
            self::delta(['tool_calls' => [['id' => 'c', 'index' => 0, 'function' => ['name' => 'drop_tables', 'arguments' => '{}']]]], 'tool_calls'),
        ]));

        $stream = $provider->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: drop_tables.');
        iterator_to_array($stream);
    }
}
