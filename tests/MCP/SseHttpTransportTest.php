<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\SseHttpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SseHttpTransportTest extends TestCase
{
    public function test_custom_http_client_is_used_to_send_requests(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('withTimeout')
            ->with(15.0)
            ->willReturnSelf();
        $httpClient->expects($this->once())
            ->method('request')
            ->with($this->callback(
                fn (HttpRequest $request): bool => $request->uri === 'https://example.com/messages'
            ))
            ->willReturn(new HttpResponse(202, ''));

        $transport = new SseHttpTransport(
            config: [
                'url' => 'https://example.com/sse',
                'timeout' => 15,
            ],
            httpClient: $httpClient,
        );

        $reflection = new ReflectionClass($transport);
        $reflection->getProperty('connected')->setValue($transport, true);
        $reflection->getProperty('postEndpointUrl')->setValue($transport, 'https://example.com/messages');

        $transport->send(['jsonrpc' => '2.0', 'method' => 'test']);
    }
}
