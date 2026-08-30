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
use NeuronAI\Providers\OrcaRouter\OrcaRouter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function json_decode;

class OrcaRouterTest extends TestCase
{
    protected string $body = '{"model": "grok-4","choices":[{"index": 0,"finish_reason": "stop","message": {"role": "assistant","content": "test response"}}],"usage": {"prompt_tokens": 19,"completion_tokens": 10,"total_tokens": 29}}';

    public function test_base_uri(): void
    {
        $provider = new OrcaRouter('', 'grok-4');
        $reflection = new ReflectionClass($provider);
        $property = $reflection->getProperty('baseUri');
        $this->assertSame('https://api.orcarouter.ai/v1', $property->getValue($provider));
    }

    public function test_chat_request(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $provider = (new OrcaRouter('', 'grok-4'))->setHttpClient(new GuzzleHttpClient(handler: $stack));

        $response = $provider->chat(new UserMessage('Hi'));
        $this->assertInstanceOf(AssistantMessage::class, $response);

        // Ensure we sent one request.
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'grok-4',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Hi',
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode((string) $request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
        $this->assertSame('stop', $response->stopReason());
    }
}
