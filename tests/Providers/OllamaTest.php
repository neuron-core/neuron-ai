<?php

namespace NeuronAI\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Image;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Ollama\Ollama;
use PHPUnit\Framework\TestCase;

class OllamaTest extends TestCase
{
    protected string $body = '{"model":"llama3.2","created_at":"2025-03-28T11:00:23.692962Z","message":{"role":"assistant","content":"test response"},"done_reason":"stop","done":true,"total_duration":497173583,"load_duration":33707083,"prompt_eval_count":32,"prompt_eval_duration":321682834,"eval_count":8,"eval_duration":140963041}';

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

        $provider = (new Ollama(
            url: '',
            model: 'llama3.2',
        ))->setClient($client);

        $response = $provider->chat([new UserMessage('Hi')]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'stream' => false,
            'model' => 'llama3.2',
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

        $provider = (new Ollama(
            url: '',
            model: 'llama3.2',
        ))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addImage(new Image('base_64_encoded_image', Image::TYPE_BASE64));

        $response = $provider->chat([$message]);

        // Ensure we sent one request
        $this->assertCount(1, $sentRequests);
        $request = $sentRequests[0];

        // Ensure we have sent the expected request payload.
        $expectedRequest = [
            'stream' => false,
            'model' => 'llama3.2',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Describe this image',
                    'images' => ['base_64_encoded_image'],
                ],
            ],
        ];

        $this->assertSame($expectedRequest, json_decode($request['request']->getBody()->getContents(), true));
        $this->assertSame('test response', $response->getContent());
    }

    public function test_chat_with_url_image_fail()
    {
        $sentRequests = [];
        $history = Middleware::history($sentRequests);
        $mockHandler = new MockHandler([
            new Response(status: 200, body: $this->body),
        ]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push($history);

        $client = new Client(['handler' => $stack]);

        $provider = (new Ollama(
            url: '',
            model: 'llama3.2',
        ))->setClient($client);

        $message = (new UserMessage('Describe this image'))
            ->addImage(new Image('base_64_encoded_image'));

        $this->expectException(ProviderException::class);
        $provider->chat([$message]);
    }
}
