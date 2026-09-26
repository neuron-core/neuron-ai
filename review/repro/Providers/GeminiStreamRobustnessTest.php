<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class GeminiStreamRobustnessTest extends TestCase
{
    protected function provider(string $body): Gemini
    {
        return new Gemini('key', 'gemini-2.5-flash', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)])),
        ));
    }

    public function test_usage_only_chunk_without_candidates_is_accepted(): void
    {
        $stream = $this->provider(
            '[{"candidates":[{"content":{"parts":[{"text":"a"}]}}]},'
            .'{"usageMetadata":{"promptTokenCount":1,"candidatesTokenCount":2}}]'
        )->stream(new UserMessage('Hi'));

        iterator_to_array($stream, false);
        $message = $stream->getReturn()->message();

        $this->assertSame('a', $message->getContent());
        $this->assertSame(2, $message->getUsage()->outputTokens);
    }

    public function test_blocked_prompt_raises_provider_exception(): void
    {
        $stream = $this->provider('[{"promptFeedback":{"blockReason":"SAFETY"}}]')->stream(new UserMessage('Hi'));

        $this->expectException(ProviderException::class);
        iterator_to_array($stream, false);
    }

    public function test_every_part_of_a_multi_part_chunk_is_processed(): void
    {
        $stream = $this->provider(
            '[{"candidates":[{"content":{"parts":[{"text":"think","thought":true},{"text":"answer"}]},"finishReason":"STOP"}]}]'
        )->stream(new UserMessage('Hi'));

        iterator_to_array($stream, false);
        $message = $stream->getReturn()->message();

        $this->assertSame('think', $message->getReasoning()?->content);
        $this->assertSame('answer', $message->getContent());
    }

    public function test_text_and_image_parts_in_one_chunk_are_both_kept(): void
    {
        $stream = $this->provider(
            '[{"candidates":[{"content":{"parts":[{"text":"here"},{"inlineData":{"mimeType":"image/png","data":"AAAA"}}]},"finishReason":"STOP"}]}]'
        )->stream(new UserMessage('Draw'));

        iterator_to_array($stream, false);
        $message = $stream->getReturn()->message();

        $this->assertSame('here', $message->getContent());
        $this->assertCount(2, $message->getContentBlocks());
    }
}
