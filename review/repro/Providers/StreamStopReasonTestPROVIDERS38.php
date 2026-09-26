<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class StreamStopReasonTestPROVIDERS38 extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_anthropic_streamed_tool_call_reports_the_tool_use_stop_reason(): void
    {
        $body = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'clock', 'input' => []]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
        ]);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('clock')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Time?')));

        $this->assertSame('tool_use', $message->stopReason());
    }

    public function test_anthropic_stop_reason_does_not_leak_into_the_next_stream(): void
    {
        $finished = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Done']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
        ]);
        $interrupted = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Cut']],
        ]);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $finished), new Response(200, body: $interrupted)));

        $this->consumeStream($provider->stream(new UserMessage('First')));
        [, $second] = $this->consumeStream($provider->stream(new UserMessage('Second')));

        $this->assertNull($second->stopReason());
    }

    public function test_openai_streamed_message_reports_the_finish_reason(): void
    {
        $body = self::sseBody([['choices' => [['index' => 0, 'delta' => ['content' => 'Partial'], 'finish_reason' => 'length']]]]);
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame('length', $message->stopReason());
    }

    public function test_openai_streamed_tool_call_reports_the_finish_reason(): void
    {
        $body = self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'clock', 'arguments' => '{}']]]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
        ]);
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('clock')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Time?')));

        $this->assertSame('tool_calls', $message->stopReason());
    }
}
