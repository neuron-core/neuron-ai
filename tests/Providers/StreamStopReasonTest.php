<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use Generator;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\AWS\BedrockRuntime;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Tests\Providers\AWS\Stub\BedrockStreamClient;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

/**
 * Every stream reports why it stopped, as chat() does: it is how a caller
 * tells a truncated answer from a complete one.
 */
class StreamStopReasonTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected function stopReasonOf(Generator $stream): ?string
    {
        [, $message] = $this->consumeStream($stream);
        $this->assertInstanceOf(AssistantMessage::class, $message);

        return $message->stopReason();
    }

    public function test_bedrock_reports_the_stop_reason(): void
    {
        $provider = new BedrockRuntime(new BedrockStreamClient([
            ['contentBlockDelta' => ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hi']]],
            ['messageStop' => ['stopReason' => 'max_tokens']],
        ]), 'model');

        $this->assertSame('max_tokens', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }

    public function test_bedrock_reports_the_stop_reason_of_a_tool_call(): void
    {
        $provider = new BedrockRuntime(new BedrockStreamClient([
            ['contentBlockStart' => ['contentBlockIndex' => 0, 'start' => ['toolUse' => ['name' => 'clock', 'toolUseId' => 'id-1']]]],
            ['contentBlockStop' => ['contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'tool_use']],
        ]), 'model');
        $provider->setTools([new ToolStub('clock')]);

        $this->assertSame('tool_use', $this->stopReasonOf($provider->stream(new UserMessage('Time?'))));
    }

    public function test_ollama_reports_the_done_reason(): void
    {
        $provider = new Ollama('http://localhost:11434/api', 'llama3.2', httpClient: $this->recordingClient(new Response(200, body: '{"message":{"role":"assistant","content":"Hi"},"done":false}'."\n"
            .'{"message":{"role":"assistant","content":""},"done":true,"done_reason":"length"}'."\n")));

        $this->assertSame('length', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }

    public function test_cohere_reports_the_finish_reason(): void
    {
        $provider = new Cohere('key', 'command-a', httpClient: $this->recordingClient(new Response(200, body: 'data: {"type":"content-delta","index":0,"delta":{"message":{"content":{"text":"Hi"}}}}'."\n\n"
            .'data: {"type":"message-end","delta":{"finish_reason":"MAX_TOKENS"}}'."\n\n")));

        $this->assertSame('MAX_TOKENS', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }

    public function test_anthropic_reports_the_stop_reason_of_a_tool_call(): void
    {
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_a', 'name' => 'clock', 'input' => []]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 5]],
        ]))));
        $provider->setTools([new ToolStub('clock')]);

        $this->assertSame('tool_use', $this->stopReasonOf($provider->stream(new UserMessage('Time?'))));
    }

    public function test_anthropic_does_not_reuse_the_previous_streams_stop_reason(): void
    {
        $finished = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Done']],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
        ]);
        // A dropped connection: no message_delta
        $interrupted = self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Cut']],
        ]);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $finished), new Response(200, body: $interrupted)));

        $this->assertSame('end_turn', $this->stopReasonOf($provider->stream(new UserMessage('First'))));
        $this->assertNull($this->stopReasonOf($provider->stream(new UserMessage('Second'))));
    }

    public function test_openai_reports_the_finish_reason(): void
    {
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Partial'], 'finish_reason' => 'length']]],
        ]))));

        $this->assertSame('length', $this->stopReasonOf($provider->stream(new UserMessage('Hi'))));
    }

    public function test_openai_reports_the_finish_reason_of_a_tool_call(): void
    {
        $provider = new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'clock', 'arguments' => '{}']]]], 'finish_reason' => null]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
        ]))));
        $provider->setTools([new ToolStub('clock')]);

        $this->assertSame('tool_calls', $this->stopReasonOf($provider->stream(new UserMessage('Time?'))));
    }
}
