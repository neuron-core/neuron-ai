<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class OpenAIStreamTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected function provider(array $events): OpenAI
    {
        $body = self::sseBody($events)."data: [DONE]\n\n";
        $provider = new OpenAI('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('weather'), new ToolStub('clock')]);

        return $provider;
    }

    /**
     * @param array<string, mixed> $delta
     * @return array<string, mixed>
     */
    protected static function chunk(array $delta, ?string $finishReason = null): array
    {
        return ['id' => 'chatcmpl-vendor', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finishReason]]];
    }

    /**
     * @return array<string, mixed>
     */
    protected static function argumentDelta(int $index, string $arguments): array
    {
        return self::chunk(['tool_calls' => [['index' => $index, 'function' => ['arguments' => $arguments]]]]);
    }

    /**
     * @param StreamChunk[] $chunks
     * @return list<array{0: string|null, 1: string, 2: string}>
     */
    protected function argumentChunks(array $chunks): array
    {
        return array_map(
            fn (ToolArgumentChunk $chunk): array => [$chunk->toolCallId, $chunk->toolName, $chunk->delta],
            array_values(array_filter($chunks, fn (StreamChunk $chunk): bool => $chunk instanceof ToolArgumentChunk)),
        );
    }

    public function test_stream_request_asks_for_usage_and_prepends_the_system_prompt(): void
    {
        $provider = $this->provider([self::chunk(['content' => 'Hi'], 'stop')])->systemPrompt('Be concise');

        $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['POST https://api.openai.com/v1/chat/completions'], $this->sentTargets());
        $this->assertTrue($body['stream']);
        $this->assertSame(['include_usage' => true], $body['stream_options']);
        $this->assertSame('system', $body['messages'][0]['role']);
        $this->assertSame(['weather', 'clock'], array_map(fn (array $tool): string => $tool['function']['name'], $body['tools']));
    }

    public function test_interleaved_parallel_tool_calls_are_assembled_per_index(): void
    {
        $events = [
            self::chunk(['role' => 'assistant', 'content' => 'Let me check.']),
            self::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '']]]]),
            self::argumentDelta(0, '{"ci'),
            self::chunk(['tool_calls' => [['index' => 1, 'id' => 'call_b', 'type' => 'function', 'function' => ['name' => 'clock', 'arguments' => '{"tz":']]]]),
            self::argumentDelta(0, 'ty":"Ro'),
            self::argumentDelta(1, '"Europe/Rome"}'),
            self::argumentDelta(0, 'me"}'),
            self::chunk([], 'tool_calls'),
        ];

        [$chunks, $message] = $this->consumeStream($this->provider($events)->stream(new UserMessage('Weather and time?')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Let me check.', $message->getContent());
        $calls = $message->getToolCalls();
        $this->assertSame(['weather', 'clock'], [$calls[0]->getName(), $calls[1]->getName()]);
        $this->assertSame(['call_a', 'call_b'], [$calls[0]->getCallId(), $calls[1]->getCallId()]);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame(['tz' => 'Europe/Rome'], $calls[1]->getInputs());
        $this->assertSame([
            ['call_a', 'weather', '{"ci'],
            ['call_b', 'clock', '{"tz":'],
            ['call_a', 'weather', 'ty":"Ro'],
            ['call_b', 'clock', '"Europe/Rome"}'],
            ['call_a', 'weather', 'me"}'],
        ], $this->argumentChunks($chunks));
    }

    public function test_argument_deltas_for_an_unannounced_tool_call_are_not_emitted(): void
    {
        $events = [
            self::argumentDelta(3, '{"orphan":true}'),
            self::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'clock', 'arguments' => '{}']]]]),
            self::chunk([], 'tool_calls'),
        ];

        [$chunks, $message] = $this->consumeStream($this->provider($events)->stream(new UserMessage('Time?')));

        $this->assertSame([['call_a', 'clock', '{}']], $this->argumentChunks($chunks));
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertCount(1, $message->getToolCalls());
    }

    public function test_streamed_call_to_an_unregistered_tool_is_rejected(): void
    {
        $events = [
            self::chunk(['tool_calls' => [['index' => 0, 'id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'shell_exec', 'arguments' => '{}']]]]),
            self::chunk([], 'tool_calls'),
        ];

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('non-existing tool: shell_exec');

        $this->consumeStream($this->provider($events)->stream(new UserMessage('Hi')));
    }

    public function test_usage_only_final_chunk_reports_cached_and_reasoning_tokens(): void
    {
        $events = [
            self::chunk(['content' => 'Hi'], 'stop'),
            ['id' => 'chatcmpl-vendor', 'choices' => [], 'usage' => [
                'prompt_tokens' => 20,
                'completion_tokens' => 9,
                'prompt_tokens_details' => ['cached_tokens' => 16],
                'completion_tokens_details' => ['reasoning_tokens' => 5],
            ]],
        ];

        [, $message] = $this->consumeStream($this->provider($events)->stream(new UserMessage('Hi')));

        $usage = $message->getUsage();
        $this->assertSame([20, 9, 16, 5], [$usage->inputTokens, $usage->outputTokens, $usage->cachedInputTokens, $usage->reasoningTokens]);
    }

    public function test_stream_ending_without_finish_reason_returns_the_collected_text(): void
    {
        $body = self::sseBody([self::chunk(['content' => 'Hel']), self::chunk(['content' => 'lo'])]);
        $provider = new OpenAI('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Hel', 'lo'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Hello', $message->getContent());
    }

    public function test_malformed_event_payload_aborts_the_stream(): void
    {
        $body = self::sseBody([self::chunk(['content' => 'Hel'])])."data: {\"choices\":[{\"delta\"\n\n";
        $provider = new OpenAI('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Streaming error');

        $this->consumeStream($provider->stream(new UserMessage('Hi')));
    }
}
