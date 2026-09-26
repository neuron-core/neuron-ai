<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\Gemini\Gemini;
use PHPUnit\Framework\TestCase;

class GeminiTruncatedUsageTest extends TestCase
{
    public function test_max_tokens_answer_without_parts_keeps_its_usage(): void
    {
        // Thinking models can spend the whole budget on thoughts: no parts, but tokens were billed.
        $provider = new Gemini('key', 'gemini-2.5-flash', httpClient: new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
            new Response(200, body: '{"candidates":[{"content":{"role":"model"},"finishReason":"MAX_TOKENS"}],'
                .'"usageMetadata":{"promptTokenCount":10,"candidatesTokenCount":0,"thoughtsTokenCount":1024}}'),
        ]))));

        $usage = $provider->chat(new UserMessage('Hi'))->message()->getUsage();

        $this->assertSame([10, 1024], [$usage?->inputTokens, $usage?->reasoningTokens]);
    }
}
