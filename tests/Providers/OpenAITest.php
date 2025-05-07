<?php

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use PHPUnit\Framework\TestCase;

class OpenAITest extends TestCase
{
    protected string $body = '{"model": "gpt-4o","choices":[{"index": 0,"finish_reason": "stop","message": {"role": "assistant","content": "test response"}}],"usage": {"prompt_tokens": 19,"completion_tokens": 10,"total_tokens": 29}}';

    public function test_chat_request(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new OpenAI('', 'gpt-4o'))->setClient($client);

        $response = $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Hi',
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
    }

    public function test_chat_with_base64_image(): void
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new OpenAI('', 'gpt-4o'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addImage(new Image('base_64_encoded_image', Image::TYPE_BASE64, 'image/jpeg'));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Describe this image'],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,base_64_encoded_image']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
    }
}
