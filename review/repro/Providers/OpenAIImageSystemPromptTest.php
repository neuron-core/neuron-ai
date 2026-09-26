<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Image;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\Image\OpenAIImage;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;

use function json_decode;

class OpenAIImageSystemPromptTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_system_prompt_does_not_mutate_the_callers_message(): void
    {
        $provider = new OpenAIImage('key', 'gpt-image-1', httpClient: $this->recordingClient(
            new Response(200, body: '{"data":[{"b64_json":"IMG"}]}'),
            new Response(200, body: '{"data":[{"b64_json":"IMG"}]}'),
        ));
        $provider->systemPrompt('Watercolor style');
        $prompt = new UserMessage('A red fox');

        $provider->chat($prompt);
        $provider->chat($prompt);

        $this->assertCount(1, $prompt->getContentBlocks());
        $this->assertSame('A red fox', $prompt->getContent());
        $this->assertSame('A red fox Watercolor style', $this->sentPrompt(0));
        $this->assertSame('A red fox Watercolor style', $this->sentPrompt(1));
    }

    public function test_streaming_with_a_system_prompt_does_not_mutate_the_callers_message(): void
    {
        $sse = "data: {\"type\":\"image_generation.completed\",\"b64_json\":\"IMG\"}\n\n";
        $provider = new OpenAIImage('key', 'gpt-image-1', httpClient: $this->recordingClient(
            new Response(200, body: $sse),
        ));
        $provider->systemPrompt('Watercolor style');
        $prompt = new UserMessage('A red fox');

        $stream = $provider->stream($prompt);
        foreach ($stream as $chunk) {
        }

        $this->assertCount(1, $prompt->getContentBlocks());
        $this->assertSame('A red fox Watercolor style', $this->sentPrompt(0));
    }

    protected function sentPrompt(int $index): string
    {
        return json_decode((string) $this->sentRequests[$index]['request']->getBody(), true)['prompt'];
    }
}
