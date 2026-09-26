<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

class ResponsesRefusalTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_refusal_output_does_not_crash_the_response_parser(): void
    {
        $body = '{"status":"completed","output":[{"type":"message","content":[{"type":"refusal","refusal":"I cannot help with that."}]}]}';
        $provider = new OpenAIResponses('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $message = $provider->chat(new UserMessage('Something forbidden'))->message();

        $this->assertStringContainsString('I cannot help with that.', (string) $message->getContent());
    }

    public function test_every_output_text_part_of_a_message_is_kept(): void
    {
        $body = '{"status":"completed","output":[{"type":"message","content":[{"type":"output_text","text":"First.","annotations":[]},{"type":"output_text","text":"Second.","annotations":[]}]}]}';
        $provider = new OpenAIResponses('key', 'model', httpClient: $this->recordingClient(new Response(200, body: $body)));

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertStringContainsString('First.', (string) $message->getContent());
        $this->assertStringContainsString('Second.', (string) $message->getContent());
    }
}
