<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\ZAI\ZAI;
use PHPUnit\Framework\TestCase;

class ZAITest extends TestCase
{
    public function test_chat_uses_default_base_uri(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model":"glm-5.2","choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Hello!"}}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new ZAI('', 'glm-5.2'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0]['request'];
        $this->assertSame('chat/completions', (string) $request->getUri());
    }

    public function test_chat_uses_custom_coding_base_uri(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(
                status: 200,
                body: '{"model":"glm-5.2","choices":[{"index":0,"finish_reason":"stop","message":{"role":"assistant","content":"Hello!"}}],"usage":{"prompt_tokens":10,"completion_tokens":5,"total_tokens":15}}',
            ),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new ZAI(
            key: '',
            model: 'glm-5.2',
            baseUri: 'https://api.z.ai/api/coding/paas/v4',
        ))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0]['request'];
        $this->assertSame('chat/completions', (string) $request->getUri());
    }
}
