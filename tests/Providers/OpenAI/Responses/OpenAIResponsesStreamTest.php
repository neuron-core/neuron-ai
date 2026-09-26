<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use ErrorException;
use Generator;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function json_decode;
use function restore_error_handler;
use function set_error_handler;

use const JSON_THROW_ON_ERROR;

class OpenAIResponsesStreamTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected function provider(string $body): OpenAIResponses
    {
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('weather'), new ToolStub('clock')]);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function functionCallAdded(string $itemId, string $callId, string $name): array
    {
        return ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => $itemId, 'call_id' => $callId, 'name' => $name, 'arguments' => '']];
    }

    public function test_stream_request_sets_the_stream_flag(): void
    {
        $body = self::sseBody([['type' => 'response.completed', 'response' => ['output' => []]]]);

        $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));

        $sent = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['POST https://api.openai.com/v1/responses'], $this->sentTargets());
        $this->assertTrue($sent['stream']);
    }

    public function test_parallel_function_calls_are_keyed_by_item_id(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Checking.'],
            self::functionCallAdded('fc_a', 'call_a', 'weather'),
            self::functionCallAdded('fc_b', 'call_b', 'clock'),
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_b', 'delta' => '{"tz":"UTC"}'],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_a', 'delta' => '{"city":'],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_a', 'delta' => '"Rome"}'],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_unknown', 'delta' => '{"x":1}'],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_b', 'arguments' => '{"tz":"UTC"}'],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_a', 'arguments' => '{"city":"Rome"}'],
            ['type' => 'response.completed', 'response' => ['usage' => ['input_tokens' => 7, 'output_tokens' => 3, 'input_tokens_details' => ['cached_tokens' => 2], 'output_tokens_details' => ['reasoning_tokens' => 1]]]],
        ]);

        [$chunks, $message] = $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Checking.', $message->getContent());
        $calls = $message->getToolCalls();
        $this->assertSame(['call_a', 'call_b'], [$calls[0]->getCallId(), $calls[1]->getCallId()]);
        $this->assertSame(['city' => 'Rome'], $calls[0]->getInputs());
        $this->assertSame(['tz' => 'UTC'], $calls[1]->getInputs());
        $this->assertSame(
            [['call_b', '{"tz":"UTC"}'], ['call_a', '{"city":'], ['call_a', '"Rome"}']],
            array_map(
                fn (ToolArgumentChunk $chunk): array => [$chunk->toolCallId, $chunk->delta],
                array_values(array_filter($chunks, fn (StreamChunk $chunk): bool => $chunk instanceof ToolArgumentChunk)),
            ),
        );
        $usage = $message->getUsage();
        $this->assertSame([7, 3, 2, 1], [$usage->inputTokens, $usage->outputTokens, $usage->cachedInputTokens, $usage->reasoningTokens]);
    }

    public function test_the_final_arguments_of_the_done_event_win_over_the_deltas(): void
    {
        $body = self::sseBody([
            self::functionCallAdded('fc_a', 'call_a', 'weather'),
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_a', 'delta' => '{"city":"Ro'],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_a', 'arguments' => '{"city":"Rome"}'],
            ['type' => 'response.completed', 'response' => []],
        ]);

        [, $message] = $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame(['city' => 'Rome'], $message->getToolCalls()[0]->getInputs());
    }

    /**
     * Frameworks commonly turn PHP warnings into exceptions, so skipped events must not raise any.
     *
     * @param Generator<int, StreamChunk, mixed, ProviderResponse> $stream
     * @return array{0: StreamChunk[], 1: Message}
     */
    protected function consumeWithWarningsAsErrors(Generator $stream): array
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            return $this->consumeStream($stream);
        } finally {
            restore_error_handler();
        }
    }

    public function test_events_without_a_type_and_non_data_lines_are_skipped(): void
    {
        $body = "event: response.created\n: keep-alive\n"
            .self::sseBody([
                ['sequence_number' => 1],
                ['type' => 'response.in_progress', 'response' => []],
                ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Hi'],
                ['type' => 'response.completed', 'response' => ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Hi']]]]]],
            ], "\r\n");

        [$chunks, $message] = $this->consumeWithWarningsAsErrors($this->provider($body)->stream(new UserMessage('Hi')));

        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Hi', $message->getContent());
    }

    public function test_failed_response_event_raises_its_error_message(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Hi'],
            ['type' => 'response.failed', 'response' => ['error' => ['code' => 'server_error', 'message' => 'Something broke']]],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI streaming error: Something broke');

        $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));
    }

    public function test_malformed_event_payload_aborts_the_stream(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('OpenAI streaming JSON decode error');

        $this->consumeStream($this->provider("data: {\"type\":\"response.output_text.delta\",\n\n")->stream(new UserMessage('Hi')));
    }
}
