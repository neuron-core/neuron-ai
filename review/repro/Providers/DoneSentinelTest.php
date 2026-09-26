<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Providers\SSEParser;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;

class DoneSentinelTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_parser_keeps_a_payload_that_mentions_done(): void
    {
        $line = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"DONE\"}}]}\n";

        $this->assertSame(
            ['choices' => [['index' => 0, 'delta' => ['content' => 'DONE']]]],
            SSEParser::parseNextSSEEvent(new GuzzleStream(Utils::streamFor($line))),
        );
    }

    public function test_parser_still_treats_the_done_sentinel_as_end_of_stream(): void
    {
        $this->assertNull(SSEParser::parseNextSSEEvent(new GuzzleStream(Utils::streamFor("data: [DONE]\n"))));
    }

    public function test_openai_tool_arguments_containing_done_are_not_corrupted(): void
    {
        $body = self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'update_task', 'arguments' => '{"status":"']]]]]]],
            ['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'DONE"}']]]]]]],
            ['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
        ])."data: [DONE]\n\n";
        $provider = new OpenAI('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('update_task')]);

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Close it')));

        $this->assertSame(['status' => 'DONE'], $message->getToolCalls()[0]->getInputs());
    }

    public function test_openai_text_delta_containing_done_is_kept(): void
    {
        $body = self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Task is ']]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => 'DONE']]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => '.'], 'finish_reason' => 'stop']]],
        ])."data: [DONE]\n\n";
        $provider = new OpenAI('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame('Task is DONE.', $message->getContent());
    }

    public function test_anthropic_text_delta_containing_done_is_kept(): void
    {
        $body = self::sseBody([
            ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'All ']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'DONE']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
            ['type' => 'message_stop'],
        ]);
        $provider = new Anthropic('sk-test', 'claude-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame('All DONE', $message->getContent());
    }

    public function test_responses_text_delta_containing_done_is_kept(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'All '],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'DONE'],
        ]);
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame('All DONE', $message->getContent());
    }
}
