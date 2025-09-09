<?php

declare(strict_types=1);

namespace Tests\MCP;

use GuzzleHttp\Psr7\Response;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StreamableHttpTransport;
use PHPUnit\Framework\TestCase;

class StreamableHttpTransportTest extends TestCase
{
    public function testConnectValidatesUrl(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'invalid-url']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Invalid URL format');

        $transport->connect();
    }

    public function testConnectRequiresUrl(): void
    {
        $transport = new StreamableHttpTransport([]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('URL is required for HTTP transport');

        $transport->connect();
    }

    public function testSendRequiresUrl(): void
    {
        $transport = new StreamableHttpTransport([]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('URL is required for HTTP transport');

        $transport->send(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1]);
    }

    public function testReceiveWithoutSendThrowsException(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No response available. Call send() first.');

        $transport->receive();
    }

    public function testParseSSEResponseExtractsJsonData(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('parseSSEResponse');

        $sseResponse = "event: message\ndata: {\"test\":\"value\"}\n\n";
        $result = $method->invoke($transport, $sseResponse);

        $this->assertEquals('{"test":"value"}', $result);
    }

    public function testParseSSEResponseWithNoDataThrowsException(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('parseSSEResponse');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No JSON data found in SSE response');

        $method->invoke($transport, "event: message\n\n");
    }

    public function testDisconnectClearsState(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);

        // Set some state via reflection
        $reflection = new \ReflectionClass($transport);
        $sessionProperty = $reflection->getProperty('sessionId');
        $sessionProperty->setValue($transport, 'test-session');

        $responseProperty = $reflection->getProperty('lastResponse');
        $responseProperty->setValue($transport, new Response(200));

        // Disconnect should clear state
        $transport->disconnect();

        $this->assertNull($sessionProperty->getValue($transport));
        $this->assertNull($responseProperty->getValue($transport));
    }
}
