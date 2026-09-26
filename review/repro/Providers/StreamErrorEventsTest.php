<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class StreamErrorEventsTest extends TestCase
{
    use ConsumesProviderStreams;
    use RecordsHttpRequests;

    protected function assertStreamFailsWith(AIProviderInterface $provider, string $vendorMessage): void
    {
        try {
            $this->consumeStream($provider->stream(new UserMessage('Hi')));
            $this->fail('A stream interrupted by an error event must not return a partial answer as a success.');
        } catch (ProviderException $exception) {
            $this->assertStringContainsString($vendorMessage, $exception->getMessage());
        }
    }

    public function test_anthropic_error_event_aborts_the_stream(): void
    {
        $body = "event: content_block_start\n".self::sseBody([
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ])."event: content_block_delta\n".self::sseBody([
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Partial']],
        ])."event: error\n".self::sseBody([
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
        ]);

        $this->assertStreamFailsWith(new Anthropic('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body))), 'Overloaded');
    }

    public function test_openai_chat_completions_error_payload_aborts_the_stream(): void
    {
        $body = self::sseBody([
            ['choices' => [['index' => 0, 'delta' => ['content' => 'Partial']]]],
            ['error' => ['message' => 'The server had an error while processing your request.', 'type' => 'server_error']],
        ]);

        $this->assertStreamFailsWith(new OpenAI('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body))), 'The server had an error');
    }

    public function test_openai_responses_error_event_aborts_the_stream(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Partial'],
            ['type' => 'error', 'code' => 'server_error', 'message' => 'The server had an error', 'param' => null, 'sequence_number' => 2],
        ]);

        $this->assertStreamFailsWith(new OpenAIResponses('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body))), 'The server had an error');
    }
}
