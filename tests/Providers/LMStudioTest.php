<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\LMStudio\LMStudio;
use PHPUnit\Framework\TestCase;

class LMStudioTest extends TestCase
{
    public function test_chat_request(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: '{"model": "gpt-4o","choices":[{"index": 0,"finish_reason": "stop","message": {"role": "assistant","content": "test response"}}],"usage": {"prompt_tokens": 19,"completion_tokens": 10,"total_tokens": 29}}'),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new LMStudio('gpt-4o'))->setClient($client);

        $provider->chat([new UserMessage('Hi')]);

        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hi',
                ],
            ],
        ];

        $this->assertSame($expectedRequest, \json_decode((string) $request['request']->getBody()->getContents(), true));
    }
}
