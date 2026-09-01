<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StreamableHttpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class StreamableHttpTransportTest extends TestCase
{
    public function test_connect_validates_url(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'invalid-url']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Invalid URL format');

        $transport->connect();
    }

    public function test_connect_requires_url(): void
    {
        $transport = new StreamableHttpTransport([]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('URL is required for HTTP transport');

        $transport->connect();
    }

    public function test_send_requires_url(): void
    {
        $transport = new StreamableHttpTransport([]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('URL is required for HTTP transport');

        $transport->send(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1]);
    }

    public function test_receive_without_send_throws_exception(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No response available. Call send() first.');

        $transport->receive();
    }

    public function test_parse_sse_response_extracts_json_data(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);
        $reflection = new ReflectionClass($transport);
        $method = $reflection->getMethod('parseSSEResponse');

        $sseResponse = "event: message\ndata: {\"test\":\"value\"}\n\n";
        $result = $method->invoke($transport, $sseResponse);

        $this->assertEquals('{"test":"value"}', $result);
    }

    public function test_parse_sse_response_with_no_data_throws_exception(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);
        $reflection = new ReflectionClass($transport);
        $method = $reflection->getMethod('parseSSEResponse');

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No JSON data found in SSE response');

        $method->invoke($transport, "event: message\n\n");
    }

    public function test_disconnect_clears_state(): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://example.com/mcp']);

        // Set some state via reflection
        $reflection = new ReflectionClass($transport);
        $sessionProperty = $reflection->getProperty('sessionId');
        $sessionProperty->setValue($transport, 'test-session');

        $responseProperty = $reflection->getProperty('lastResponse');
        $responseProperty->setValue($transport, new HttpResponse(200, ''));

        // Disconnect should clear state
        $transport->disconnect();

        $this->assertNull($sessionProperty->getValue($transport));
        $this->assertNull($responseProperty->getValue($transport));
    }
}
