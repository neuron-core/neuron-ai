<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_shift;
use function array_slice;
use function array_values;
use function json_decode;
use function str_split;

use const JSON_THROW_ON_ERROR;

class AnthropicStreamTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected function provider(string $body): Anthropic
    {
        return new Anthropic('sk-ant-test', 'claude-test', httpClient: $this->recordingClient(new Response(200, body: $body)));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function textEvents(string ...$deltas): array
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_vendor', 'usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ];
        foreach ($deltas as $delta) {
            $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]];
        }
        $events[] = ['type' => 'content_block_stop', 'index' => 0];
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 4]];
        $events[] = ['type' => 'message_stop'];

        return $events;
    }

    /**
     * @param StreamChunk[] $chunks
     * @return ToolArgumentChunk[]
     */
    protected function argumentChunks(array $chunks): array
    {
        return array_values(array_filter($chunks, fn (StreamChunk $chunk): bool => $chunk instanceof ToolArgumentChunk));
    }

    public function test_stream_request_sets_the_stream_flag_on_the_messages_endpoint(): void
    {
        $provider = $this->provider(self::sseBody(self::textEvents('Hi')))->systemPrompt('Be concise');

        $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['POST https://api.anthropic.com/v1/messages'], $this->sentTargets());
        $this->assertTrue($body['stream']);
        $this->assertSame([['type' => 'text', 'text' => 'Be concise']], $body['system']);
    }

    public function test_events_fragmented_by_the_network_with_crlf_line_endings_are_reassembled(): void
    {
        $pieces = str_split(self::sseBody(self::textEvents('Ciao ', '👋', ' mondo'), "\r\n"), 7);
        $body = new PumpStream(static function () use (&$pieces): string|false {
            return $pieces === [] ? false : array_shift($pieces);
        });
        $provider = new Anthropic('sk-ant-test', 'claude-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Ciao ', '👋', ' mondo'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertSame('Ciao 👋 mondo', $message->getContent());
        $this->assertSame(10, $message->getUsage()->inputTokens);
    }

    public function test_event_lines_pings_and_unknown_events_are_ignored(): void
    {
        $events = self::textEvents('Answer');
        $body = "event: message_start\n: keep-alive comment\n"
            .self::sseBody([$events[0], ['type' => 'ping'], $events[1]])
            .self::sseBody([['type' => 'some_future_event', 'index' => 0, 'payload' => []]])
            .self::sseBody([
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'citations_delta', 'citation' => []]],
                ...array_slice($events, 2),
            ]);

        [$chunks, $message] = $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));

        $this->assertSame(['Answer'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Answer', $message->getContent());
        $this->assertSame('end_turn', $message->stopReason());
    }

    public function test_parallel_tool_calls_assemble_their_own_arguments_from_many_deltas(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 3, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Checking.']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'weather', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"ci']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'ty": "Ro']],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => 'me"}']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_b', 'name' => 'weather', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"city": "東']],
            ['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '京", "days": [1, 2]}']],
            ['type' => 'content_block_stop', 'index' => 2],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 9]],
        ];
        $provider = $this->provider(self::sseBody($events))->setTools([new ToolStub('weather')]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Weather?')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking.', $message->getContent());
        $calls = $message->getToolCalls();
        $this->assertSame(['toolu_a', 'toolu_b'], [$calls[0]->getCallId(), $calls[1]->getCallId()]);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame(['city' => '東京', 'days' => [1, 2]], $calls[1]->getInputs());
        $this->assertSame([1, 2], $message->getMetadata('anthropic_tool_positions'));

        $arguments = $this->argumentChunks($chunks);
        $this->assertSame(
            [['toolu_a', '{"ci'], ['toolu_a', 'ty": "Ro'], ['toolu_a', 'me"}'], ['toolu_b', '{"city": "東'], ['toolu_b', '京", "days": [1, 2]}']],
            array_map(fn (ToolArgumentChunk $chunk): array => [$chunk->toolCallId, $chunk->delta], $arguments),
        );
    }

    public function test_tool_call_without_argument_deltas_has_empty_inputs(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 3]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'now', 'input' => []]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 2]],
        ];
        $provider = $this->provider(self::sseBody($events))->setTools([new ToolStub('now')]);

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Time?')));

        $this->assertSame([], $chunks);
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame([], $message->getToolCalls()[0]->getInputs());
    }

    public function test_streamed_call_to_an_unregistered_tool_is_rejected(): void
    {
        $events = [
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'rm_rf', 'input' => []]],
            ['type' => 'content_block_stop', 'index' => 0],
        ];
        $provider = $this->provider(self::sseBody($events))->setTools([new ToolStub('now')]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('non-existing tool: rm_rf');

        $this->consumeStream($provider->stream(new UserMessage('Time?')));
    }

    public function test_truncated_answer_reports_the_max_tokens_stop_reason(): void
    {
        $events = self::textEvents('Partial');
        $events[4]['delta']['stop_reason'] = 'max_tokens';

        [, $message] = $this->consumeStream($this->provider(self::sseBody($events))->stream(new UserMessage('Hi')));

        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('max_tokens', $message->stopReason());
    }

    public function test_cache_reads_are_reported_as_cached_input_tokens(): void
    {
        $events = self::textEvents('Hi');
        $events[0]['message']['usage'] = ['input_tokens' => 10, 'output_tokens' => 0, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 20];

        [, $message] = $this->consumeStream($this->provider(self::sseBody($events))->stream(new UserMessage('Hi')));

        $this->assertSame(30, $message->getUsage()->cachedInputTokens);
        $this->assertSame(20, $message->getMetadata('cacheWriteTokens'));
        $this->assertSame(30, $message->getMetadata('cacheReadTokens'));
    }
}
