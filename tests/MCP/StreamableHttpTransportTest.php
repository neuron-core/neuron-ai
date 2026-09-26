<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpSessionLostException;
use NeuronAI\MCP\StreamableHttpTransport;
use NeuronAI\Tests\MCP\Stub\ScriptedHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_repeat;

class StreamableHttpTransportTest extends TestCase
{
    protected const URL = 'https://mcp.example.com/mcp';

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

    public function test_connect_sends_nothing(): void
    {
        $httpClient = new ScriptedHttpClient();

        (new StreamableHttpTransport(['url' => self::URL], $httpClient))->connect();

        $this->assertSame([], $httpClient->requests);
    }

    public function test_send_requires_url(): void
    {
        $transport = new StreamableHttpTransport([]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('URL is required for HTTP transport');

        $transport->send(['jsonrpc' => '2.0', 'method' => 'test', 'id' => 1]);
    }

    public function test_send_posts_the_message_as_json(): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse(202, ''));
        $transport = new StreamableHttpTransport(['url' => self::URL, 'timeout' => 7], $httpClient);

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['arguments' => ['q' => 'café/€']]]);

        $request = $httpClient->requests[0];
        $this->assertSame(HttpMethod::POST, $request->method);
        $this->assertSame(self::URL, $request->uri);
        $this->assertSame(7.0, $request->timeout);
        $this->assertSame(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['arguments' => ['q' => 'café/€']]]), $request->body);
        $this->assertSame(['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json'], $request->headers);
    }

    public function test_requests_time_out_after_thirty_seconds_by_default(): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse(202, ''));

        (new StreamableHttpTransport(['url' => self::URL], $httpClient))->send(['jsonrpc' => '2.0', 'method' => 'ping']);

        $this->assertSame(30.0, $httpClient->requests[0]->timeout);
    }

    public function test_token_and_configured_headers_authenticate_every_request(): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse(202, ''));
        $transport = new StreamableHttpTransport([
            'url' => self::URL,
            'token' => 'mcp-secret',
            'headers' => ['X-Tenant' => 'acme', 'Authorization' => 'Basic overridden', 'Accept' => 'text/html'],
        ], $httpClient);

        $transport->send(['jsonrpc' => '2.0', 'method' => 'ping']);

        $headers = $httpClient->requests[0]->headers;
        $this->assertSame('Bearer mcp-secret', $headers['Authorization']);
        $this->assertSame('acme', $headers['X-Tenant']);
        // The protocol's own headers cannot be configured away.
        $this->assertSame('application/json, text/event-stream', $headers['Accept']);
    }

    public function test_the_session_id_assigned_by_the_server_is_sent_with_later_requests(): void
    {
        $httpClient = new ScriptedHttpClient(
            new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}', ['mcp-session-id' => ['session-1']]),
            new HttpResponse(202, ''),
            new HttpResponse(202, ''),
        );
        $transport = new StreamableHttpTransport(['url' => self::URL], $httpClient);

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $transport->setProtocolVersion('2025-06-18');
        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled']);

        $this->assertArrayNotHasKey('Mcp-Session-Id', $httpClient->requests[0]->headers);
        $this->assertArrayNotHasKey('MCP-Protocol-Version', $httpClient->requests[0]->headers);
        foreach ([1, 2] as $later) {
            // A response without the header keeps the session.
            $this->assertSame('session-1', $httpClient->requests[$later]->headers['Mcp-Session-Id']);
            $this->assertSame('2025-06-18', $httpClient->requests[$later]->headers['MCP-Protocol-Version']);
        }
    }

    public function test_disconnect_forgets_the_session(): void
    {
        $httpClient = new ScriptedHttpClient(
            new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}', ['Mcp-Session-Id' => 'session-1']),
            new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}'),
        );
        $transport = new StreamableHttpTransport(['url' => self::URL], $httpClient);
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $transport->setProtocolVersion('2025-11-25');

        $transport->disconnect();

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $this->assertArrayNotHasKey('Mcp-Session-Id', $httpClient->requests[1]->headers);
        $this->assertArrayNotHasKey('MCP-Protocol-Version', $httpClient->requests[1]->headers);
    }

    public function test_disconnect_drops_unread_messages(): void
    {
        $transport = new StreamableHttpTransport(['url' => self::URL], new ScriptedHttpClient(
            new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}'),
        ));
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $transport->disconnect();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No response available. Call send() first.');
        $transport->receive();
    }

    public function test_receive_without_send_throws_exception(): void
    {
        $transport = new StreamableHttpTransport(['url' => self::URL]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No response available. Call send() first.');

        $transport->receive();
    }

    public function test_a_json_response_is_received_once(): void
    {
        $transport = $this->transportAnswering('{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}');

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]], $transport->receive());

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('No response available. Call send() first.');
        $transport->receive();
    }

    public function test_an_sse_response_yields_every_event_payload_in_order(): void
    {
        $transport = $this->transportAnswering(
            ": keep-alive comment\r\n: data: a comment is never a payload\r\n\r\n"
            . "id: 1\r\nevent: message\r\nretry: 1000\r\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\r\n\r\n"
            . "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/message\"}\r\r"
            . "event: ping\n\n"
            . "event: message\ndata: {\"jsonrpc\":\"2.0\",\ndata: \"id\":1,\"result\":{\"text\":\"a:b\"}}\n\n"
        );

        // Events end at a blank line whatever the line terminator: CRLF, CR or LF.
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/progress'], $transport->receive());
        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/message'], $transport->receive());
        // Data lines of one event join into one payload.
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['text' => 'a:b']], $transport->receive());
    }

    public function test_a_plain_json_response_nested_deeper_than_64_levels_is_refused(): void
    {
        $transport = $this->transportAnswering(str_repeat('[', 70) . str_repeat(']', 70));

        $this->expectException(McpException::class);

        $transport->receive();
    }

    public function test_a_new_send_discards_unread_messages_of_the_previous_response(): void
    {
        $httpClient = new ScriptedHttpClient(
            new HttpResponse(200, "data: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n"),
            new HttpResponse(200, '{"jsonrpc":"2.0","id":2,"result":{}}'),
        );
        $transport = new StreamableHttpTransport(['url' => self::URL], $httpClient);
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $transport->receive();

        $transport->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], $transport->receive());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'empty body' => ['', 'Empty response body'];
        yield 'html error page' => ['<html>Bad Gateway</html>', 'No JSON data found in SSE response'];
        yield 'sse without data' => ["event: message\n\n", 'No JSON data found in SSE response'];
        yield 'sse with invalid json' => ["data: {\"jsonrpc\":\n\n", 'Invalid JSON response: Syntax error'];
        yield 'nesting deeper than 64 levels' => ['data: ' . str_repeat('[', 70) . str_repeat(']', 70) . "\n\n", 'Invalid JSON response: Maximum stack depth exceeded'];
    }

    #[DataProvider('malformedBodies')]
    public function test_a_malformed_response_is_rejected(string $body, string $message): void
    {
        $transport = $this->transportAnswering($body);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage($message);

        $transport->receive();
    }

    /**
     * @return iterable<string, array{int, bool, class-string<McpException>, string}>
     */
    public static function httpErrors(): iterable
    {
        yield 'expired session' => [404, true, McpSessionLostException::class, 'The MCP session has expired'];
        yield 'unknown endpoint' => [404, false, McpException::class, 'HTTP request failed: HTTP 404 error during POST ' . self::URL . ': server says no'];
        yield 'unauthenticated' => [401, true, McpException::class, 'Authentication failed: Invalid or expired token'];
        yield 'forbidden' => [403, true, McpException::class, 'Authorization failed: Insufficient permissions'];
        yield 'server error' => [500, true, McpException::class, 'HTTP request failed: HTTP 500 error during POST ' . self::URL . ': server says no'];
    }

    /**
     * @param class-string<McpException> $exceptionClass
     */
    #[DataProvider('httpErrors')]
    public function test_http_errors_map_to_mcp_exceptions(int $status, bool $inSession, string $exceptionClass, string $message): void
    {
        $replies = $inSession ? [new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}', ['Mcp-Session-Id' => 'session-1'])] : [];
        $transport = new StreamableHttpTransport(['url' => self::URL, 'token' => 'mcp-secret'], new ScriptedHttpClient(...$replies, ...[new HttpResponse($status, 'server says no')]));
        if ($inSession) {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        }

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertSame($exceptionClass, $exception::class);
            $this->assertSame($message, $exception->getMessage());
            $this->assertInstanceOf(HttpException::class, $exception->getPrevious());
            $this->assertStringNotContainsString('mcp-secret', $exception->getMessage());
        }
    }

    public function test_a_network_error_maps_to_an_mcp_exception(): void
    {
        $request = HttpRequest::post(self::URL);
        $transport = new StreamableHttpTransport(['url' => self::URL, 'token' => 'mcp-secret'], new ScriptedHttpClient(
            HttpException::networkError($request, 'Connection refused'),
        ));

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertNotInstanceOf(McpSessionLostException::class, $exception);
            $this->assertSame('HTTP request failed: Network error during POST ' . self::URL . ': Connection refused', $exception->getMessage());
        }
    }

    public function test_a_message_that_cannot_be_encoded_is_rejected_before_sending(): void
    {
        $httpClient = new ScriptedHttpClient();
        $transport = new StreamableHttpTransport(['url' => self::URL], $httpClient);

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['arguments' => ['text' => "\xB1\x31"]]]);
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertStringStartsWith('Failed to encode JSON: ', $exception->getMessage());
        }

        $this->assertSame([], $httpClient->requests);
    }

    protected function transportAnswering(string $body): StreamableHttpTransport
    {
        $transport = new StreamableHttpTransport(['url' => self::URL], new ScriptedHttpClient(new HttpResponse(200, $body)));
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        return $transport;
    }
}
