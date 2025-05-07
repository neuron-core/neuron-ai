<?php

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;
use PHPUnit\Framework\TestCase;

class GeminiTest extends TestCase
{
    protected string $body = '{
	"candidates": [
		{
			"content": {
			    "role": "model",
			    "parts": [
                    {
                        "text": "test response"
                    }
                ]
			}
		}
	]
}';

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

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $response = $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Hi']
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
    }

    public function test_chat_with_url_image()
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addImage(new Image(
                image: '/test.png',
                mediaType: 'image/png'
            ));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this image'],
                        ['file_data' => ['file_uri' => '/test.png', 'mime_type' => 'image/png']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
    }

    public function test_chat_with_base64_image()
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new Gemini('', 'gemini-2.0-flash'))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addImage(new Image(
                           image: 'base64_encoded_image_data',
                           type: 'base64',
                           mediaType: 'image/png'
                       ));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => 'Describe this image'],
                        ['inline_data' => ['data' => 'base64_encoded_image_data', 'mime_type' => 'image/png']],
                    ],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
    }
}
