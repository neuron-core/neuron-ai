<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\TestCase;

class GuzzleStreamOptionsTest extends TestCase
{
    public function test_client_options_apply_to_streamed_requests_too(): void
    {
        $sent = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], 'data: x')]));
        $stack->push(Middleware::history($sent));
        $client = new GuzzleHttpClient(handler: $stack, options: ['verify' => '/etc/ssl/custom-ca.pem', 'proxy' => 'http://proxy.internal:3128']);

        $client->stream(HttpRequest::get('https://example.com/sse'));

        $this->assertSame('/etc/ssl/custom-ca.pem', $sent[0]['options']['verify']);
        $this->assertSame('http://proxy.internal:3128', $sent[0]['options']['proxy'] ?? null);
    }
}
