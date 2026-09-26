<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class AnthropicStreamUsageTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    public function test_cumulative_output_tokens_of_message_delta_are_not_double_counted(): void
    {
        // Anthropic documents the usage counts of message_delta as cumulative.
        $body = self::sseBody([
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 3, 'output_tokens' => 1]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello there']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 15]],
        ]);
        $provider = new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        [, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(15, $message->getUsage()->outputTokens);
        $this->assertSame(3, $message->getUsage()->inputTokens);
    }
}
