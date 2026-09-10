<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpClient;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

use function count;

class McpClientTest extends TestCase
{
    public function test_initialize_requests_the_latest_handshake_protocol_version(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        new McpClient(['transport' => $transport]);

        $transport->assertSent(
            fn (array $message): bool => $message['method'] === 'initialize'
                && $message['params']['protocolVersion'] === '2025-11-25'
                && (array) $message['params']['capabilities'] === []
        );
    }

    public function test_negotiated_protocol_version_is_handed_to_the_transport(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['protocolVersion' => '2025-06-18']]);

        new McpClient(['transport' => $transport]);

        $transport->assertProtocolVersion('2025-06-18');
    }

    public function test_requested_protocol_version_is_kept_when_the_server_omits_it(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        new McpClient(['transport' => $transport]);

        $transport->assertProtocolVersion('2025-11-25');
    }

    public function test_http_requests_after_initialize_carry_the_negotiated_protocol_version(): void
    {
        $requests = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('withHeaders')->willReturnSelf();
        $httpClient->method('withTimeout')->willReturnSelf();
        $httpClient->method('request')
            ->willReturnCallback(function (HttpRequest $request) use (&$requests): HttpResponse {
                $requests[] = $request;
                return match (count($requests)) {
                    1 => new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18"}}'),
                    2 => new HttpResponse(202, ''),
                    default => new HttpResponse(200, '{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}'),
                };
            });

        $client = new McpClient(['url' => 'https://example.com/mcp'], $httpClient);
        $client->listTools();

        $this->assertArrayNotHasKey('MCP-Protocol-Version', $requests[0]->headers);
        $this->assertSame('2025-06-18', $requests[1]->headers['MCP-Protocol-Version']);
        $this->assertSame('2025-06-18', $requests[2]->headers['MCP-Protocol-Version']);
    }
}
