<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class ResponsesIncompleteStreamTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_truncated_stream_reports_usage_and_the_incomplete_status(): void
    {
        // The Responses API ends a truncated stream with response.incomplete instead of response.completed.
        $body = self::sseBody([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Partial'],
            ['type' => 'response.incomplete', 'response' => [
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [['type' => 'message', 'id' => 'msg_1', 'content' => [['type' => 'output_text', 'text' => 'Partial']]]],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 16],
            ]],
        ]);
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame('Partial', $message->getContent());
        $this->assertSame(12, $message->getUsage()?->inputTokens);
        $this->assertSame(16, $message->getUsage()?->outputTokens);
        $this->assertSame('incomplete', $message->stopReason());
    }

    public function test_truncated_stream_keeps_the_completed_tool_calls(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'name' => 'lookup', 'call_id' => 'call_1', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.done', 'item_id' => 'fc_1', 'arguments' => '{"q":"x"}'],
            ['type' => 'response.incomplete', 'response' => [
                'status' => 'incomplete',
                'incomplete_details' => ['reason' => 'max_output_tokens'],
                'output' => [['type' => 'function_call', 'id' => 'fc_1', 'name' => 'lookup', 'call_id' => 'call_1', 'arguments' => '{"q":"x"}']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
            ]],
        ]);
        $provider = (new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body))))
            ->setTools([new ToolStub('lookup')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('call_1', $message->getToolCalls()[0]->getCallId());
        $this->assertSame(7, $message->getUsage()?->outputTokens);
    }
}
