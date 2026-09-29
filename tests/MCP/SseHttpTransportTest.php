<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\Tests\HttpClient\BootsFixtureServer;
use NeuronAI\Tests\MCP\Stub\ScriptedHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function basename;
use function file_put_contents;
use function glob;
use function is_dir;
use function microtime;
use function parse_url;
use function rawurlencode;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILE_APPEND;
use const PHP_URL_PORT;

class SseHttpTransportTest extends TestCase
{
    use BootsFixtureServer {
        tearDownAfterClass as stopFixtureServer;
    }

    public static function tearDownAfterClass(): void
    {
        static::stopFixtureServer();

        $spool = sys_get_temp_dir() . '/neuron-mcp-sse-' . parse_url(static::$baseUri, PHP_URL_PORT);
        if (is_dir($spool)) {
            array_map(unlink(...), glob("{$spool}/*") ?: []);
            rmdir($spool);
        }
    }

    public function test_a_session_runs_over_the_event_stream(): void
    {
        $client = new McpClient(['url' => static::$baseUri . '/sse', 'async' => true, 'timeout' => 5]);

        $this->assertSame(
            [['name' => 'echo', 'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]]]],
            $client->listTools(),
        );
        // Comments, other events, invalid JSON and non JSON-RPC payloads precede every answer.
        $this->assertSame([['type' => 'text', 'text' => 'héllo 世界']], $client->callTool('echo', ['value' => 'héllo 世界'])['result']['content']);
    }

    public function test_receive_returns_the_next_json_rpc_message_and_skips_other_events(): void
    {
        $transport = new SseHttpTransport(['url' => static::$baseUri . '/sse', 'timeout' => 5]);
        $transport->connect();

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list']);

            // Pings, comments, invalid JSON and a JSON payload without "jsonrpc" come first.
            $this->assertSame(
                ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['tools' => [['name' => 'echo', 'inputSchema' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string']]]]]]],
                $transport->receive(),
            );
        } finally {
            $transport->disconnect();
        }
    }

    public function test_a_data_only_event_is_a_message(): void
    {
        // SSE types an event without an "event:" field as "message".
        $client = new McpClient(['url' => static::$baseUri . '/sse?bare=1', 'async' => true, 'timeout' => 5]);

        $this->assertSame([['type' => 'text', 'text' => 'bare']], $client->callTool('echo', ['value' => 'bare'])['result']['content']);
    }

    public function test_credentials_reach_both_the_stream_and_the_posts(): void
    {
        $client = new McpClient([
            'url' => static::$baseUri . '/sse',
            'async' => true,
            'timeout' => 5,
            'token' => 'mcp-secret',
            'headers' => ['X-Tenant' => 'acme'],
        ]);

        $result = $client->callTool('echo', ['value' => 'x'])['result'];

        foreach (['streamHeaders', 'postHeaders'] as $request) {
            $this->assertSame('Bearer mcp-secret', $result[$request]['authorization'], $request);
            $this->assertSame('acme', $result[$request]['x-tenant'], $request);
        }
        $this->assertSame('text/event-stream', $result['streamHeaders']['accept']);
        $this->assertSame('application/json', $result['postHeaders']['content-type']);
    }

    public function test_the_session_id_announced_by_the_stream_is_sent_with_every_post(): void
    {
        $withSession = new McpClient(['url' => static::$baseUri . '/sse?session=session-42', 'async' => true, 'timeout' => 5]);
        $withoutSession = new McpClient(['url' => static::$baseUri . '/sse', 'async' => true, 'timeout' => 5]);

        $this->assertSame('session-42', $withSession->callTool('echo')['result']['postHeaders']['mcp-session-id']);
        $this->assertArrayNotHasKey('mcp-session-id', $withoutSession->callTool('echo')['result']['postHeaders']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function acceptedEndpoints(): iterable
    {
        yield 'absolute path' => ['/sse', '/messages?session=1', '{base}/messages?session=1'];
        yield 'path relative to the stream directory' => ['/v1/mcp/sse', 'messages?session=1', '{base}/v1/mcp/messages?session=1'];
        yield 'relative path at the root' => ['/sse', 'messages', '{base}/messages'];
        yield 'absolute url on the same origin' => ['/sse', '{base}/rpc', '{base}/rpc'];
    }

    #[DataProvider('acceptedEndpoints')]
    public function test_the_announced_endpoint_receives_the_posts(string $streamPath, string $endpoint, string $expected): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse(202, ''));
        $transport = $this->transport($streamPath . '?endpoint=' . rawurlencode($this->expand($endpoint)), $httpClient);

        $transport->connect();
        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $transport->disconnect();

        $this->assertSame($this->expand($expected), $httpClient->requests[0]->uri);
    }

    public function test_a_relative_endpoint_keeps_the_credentials_of_the_stream_url(): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse(202, ''));
        $transport = new SseHttpTransport(['url' => str_replace('http://', 'http://user:p%40ss@', static::$baseUri) . '/sse?endpoint=/messages', 'timeout' => 5], $httpClient);

        $transport->connect();
        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $transport->disconnect();

        $this->assertSame(str_replace('http://', 'http://user:p%40ss@', static::$baseUri) . '/messages', $httpClient->requests[0]->uri);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignEndpoints(): iterable
    {
        yield 'another host' => ['http://evil.example/messages'];
        yield 'protocol-relative url' => ['//evil.example/messages'];
        yield 'same host name, other port' => ['http://127.0.0.1:1/messages'];
        yield 'same host, default port' => ['http://127.0.0.1/messages'];
        yield 'other scheme' => ['https://127.0.0.1:{port}/messages'];
        yield 'origin disguised as credentials' => ['http://127.0.0.1:{port}@evil.example/messages'];
        yield 'other name for the same machine' => ['http://localhost:{port}/messages'];
    }

    /**
     * The endpoint comes from the server: accepting any host would let it redirect
     * the client's credentials and tool calls to a third party.
     */
    #[DataProvider('foreignEndpoints')]
    public function test_an_endpoint_on_another_origin_is_refused(string $endpoint): void
    {
        $httpClient = new ScriptedHttpClient();
        $transport = $this->transport('/sse?endpoint=' . rawurlencode($this->expand($endpoint)), $httpClient, ['token' => 'mcp-secret']);

        try {
            $transport->connect();
            $this->fail('A foreign endpoint must be refused');
        } catch (McpException $exception) {
            $this->assertSame('Endpoint URL must point to the same host as the MCP server', $exception->getMessage());
        }

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Not connected to server. Call connect() first.');
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failedStreams(): iterable
    {
        yield 'unauthorized' => [401, 'Failed to open SSE connection to: {base}/sse?status=401'];
        yield 'server error' => [500, 'Failed to open SSE connection to: {base}/sse?status=500'];
        yield 'success without a stream' => [204, 'SSE connection failed: HTTP/1.1 204 No Content'];
    }

    #[DataProvider('failedStreams')]
    public function test_a_stream_that_does_not_open_fails_the_connection(int $status, string $message): void
    {
        $transport = $this->transport("/sse?status={$status}", new ScriptedHttpClient(), ['token' => 'mcp-secret']);

        try {
            $transport->connect();
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertSame($this->expand($message), $exception->getMessage());
            $this->assertStringNotContainsString('mcp-secret', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function headersWithLineBreaks(): iterable
    {
        yield 'token' => [['token' => "secret\r\nX-Injected: yes"]];
        yield 'header value' => [['headers' => ['X-Tenant' => "acme\nX-Injected: yes"]]];
        yield 'header name' => [['headers' => ["X-Tenant\r\nX-Injected" => 'yes']]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('headersWithLineBreaks')]
    public function test_line_breaks_in_headers_are_refused(array $config): void
    {
        $transport = $this->transport('/sse', new ScriptedHttpClient(), $config);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Header values must not contain line breaks');

        $transport->connect();
    }

    public function test_connect_validates_the_url(): void
    {
        foreach ([[[], 'URL is required for SSE HTTP transport'], [['url' => 'not a url'], 'Invalid URL format']] as [$config, $message]) {
            try {
                (new SseHttpTransport($config, new ScriptedHttpClient()))->connect();
                $this->fail('Expected McpException was not thrown');
            } catch (McpException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_messages_cannot_be_exchanged_before_connecting(): void
    {
        $transport = new SseHttpTransport(['url' => static::$baseUri . '/sse'], new ScriptedHttpClient());

        $send = function () use ($transport): void {
            $transport->send(['jsonrpc' => '2.0', 'method' => 'ping']);
        };

        foreach ([$send, $transport->receive(...)] as $exchange) {
            try {
                $exchange();
                $this->fail('Expected McpException was not thrown');
            } catch (McpException $exception) {
                $this->assertSame('Not connected to server. Call connect() first.', $exception->getMessage());
            }
        }
    }

    public function test_disconnect_ends_the_exchange_until_the_next_connect(): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse(202, ''));
        $transport = $this->transport('/sse', $httpClient);
        $transport->connect();

        $transport->disconnect();

        try {
            $transport->send(['jsonrpc' => '2.0', 'method' => 'ping']);
            $this->fail('A disconnected transport must not send');
        } catch (McpException $exception) {
            $this->assertSame('Not connected to server. Call connect() first.', $exception->getMessage());
        }

        $transport->connect();
        $transport->send(['jsonrpc' => '2.0', 'method' => 'ping']);
        $transport->disconnect();
        $this->assertCount(1, $httpClient->requests);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function acceptedPostStatuses(): iterable
    {
        yield 'accepted' => [202];
        yield 'ok' => [200];
    }

    #[DataProvider('acceptedPostStatuses')]
    public function test_a_post_the_server_accepts_is_sent(int $status): void
    {
        $httpClient = new ScriptedHttpClient(new HttpResponse($status, ''));
        $transport = $this->transport('/sse?endpoint=/messages', $httpClient);
        $transport->connect();

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        } finally {
            $transport->disconnect();
        }

        $this->assertSame('{"jsonrpc":"2.0","id":1,"method":"ping"}', $httpClient->requests[0]->body);
    }

    public function test_a_new_connection_does_not_present_the_previous_session_id(): void
    {
        $transport = new SseHttpTransport(['url' => static::$baseUri . '/sse?session=reconnected', 'timeout' => 5]);
        $transport->connect();
        $transport->disconnect();

        $transport->connect();
        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'echo']]);
            $result = $transport->receive()['result'];
        } finally {
            $transport->disconnect();
        }

        $this->assertArrayNotHasKey('mcp-session-id', $result['streamHeaders']);
        $this->assertSame('reconnected', $result['postHeaders']['mcp-session-id']);
    }

    /**
     * @return iterable<string, array{HttpResponse, string}>
     */
    public static function rejectedPosts(): iterable
    {
        yield 'no content' => [new HttpResponse(204, ''), 'POST request failed with status 204: (no body)'];
        yield 'created' => [new HttpResponse(201, 'created'), 'POST request failed with status 201: created'];
        yield 'server error' => [new HttpResponse(500, 'boom'), 'HTTP POST failed: HTTP 500 error during POST {base}/messages: boom'];
    }

    #[DataProvider('rejectedPosts')]
    public function test_a_post_the_server_does_not_accept_fails(HttpResponse $response, string $message): void
    {
        $transport = $this->transport('/sse?endpoint=/messages', new ScriptedHttpClient($response));
        $transport->connect();

        try {
            $transport->send(['jsonrpc' => '2.0', 'method' => 'ping']);
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertSame($this->expand($message), $exception->getMessage());
        } finally {
            $transport->disconnect();
        }
    }

    public function test_receive_gives_up_after_the_configured_timeout(): void
    {
        $transport = $this->transport('/sse', new ScriptedHttpClient(), ['timeout' => 0.2]);
        $transport->connect();

        try {
            $transport->receive();
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertSame('Timeout waiting for response from server', $exception->getMessage());
        } finally {
            $transport->disconnect();
        }
    }

    public function test_a_stream_closed_after_the_endpoint_fails_receive_at_once(): void
    {
        $transport = $this->transport('/sse?close=1', new ScriptedHttpClient(), ['timeout' => 3]);
        $transport->connect();
        $started = microtime(true);

        try {
            $transport->receive();
            $this->fail('A closed stream must not be waited on');
        } catch (McpException $exception) {
            $this->assertSame('SSE stream closed by server', $exception->getMessage());
            $this->assertLessThan(1.0, microtime(true) - $started);
        }
    }

    public function test_a_stream_closed_before_the_endpoint_fails_the_connection_at_once(): void
    {
        $transport = $this->transport('/sse?status=200', new ScriptedHttpClient(), ['timeout' => 3]);
        $started = microtime(true);

        try {
            $transport->connect();
            $this->fail('A closed stream must not be waited on');
        } catch (McpException $exception) {
            $this->assertSame('SSE stream closed by server', $exception->getMessage());
            $this->assertLessThan(1.0, microtime(true) - $started);
        }
    }

    public function test_a_response_sent_with_the_endpoint_is_delivered(): void
    {
        $script = $this->script("event: endpoint\ndata: /messages\n\nevent: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n");

        try {
            $transport = $this->transport('/sse?quiet=1&script=' . basename($script), new ScriptedHttpClient(), ['timeout' => 1]);
            $transport->connect();

            $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $transport->receive());
        } finally {
            unlink($script);
        }
    }

    public function test_messages_arriving_together_are_delivered_one_by_one(): void
    {
        $script = $this->script("event: endpoint\ndata: /messages\n\n");

        try {
            $transport = $this->transport('/sse?quiet=1&script=' . basename($script), new ScriptedHttpClient(), ['timeout' => 1]);
            $transport->connect();
            file_put_contents(
                $script,
                "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\n"
                . "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n",
                FILE_APPEND,
            );

            $this->assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/progress'], $transport->receive());
            $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $transport->receive());
        } finally {
            unlink($script);
        }
    }

    public function test_the_endpoint_is_awaited_for_the_configured_timeout(): void
    {
        // A comment opens the stream, so the server sends its headers
        $script = $this->script(": waiting\n\n");
        $transport = $this->transport('/sse?quiet=1&script=' . basename($script), new ScriptedHttpClient(), ['timeout' => 0.5]);
        $started = microtime(true);

        try {
            $transport->connect();
            $this->fail('A stream that never announces its endpoint must not be waited on longer than the timeout');
        } catch (McpException $exception) {
            $this->assertSame('Timeout waiting for endpoint event from server', $exception->getMessage());
            $this->assertLessThan(2.0, microtime(true) - $started);
        } finally {
            unlink($script);
        }
    }

    /**
     * A file the fixture relays as the event stream, so a test scripts its exact bytes.
     */
    protected function script(string $bytes): string
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'neuron-sse-');
        file_put_contents($script, $bytes);

        return $script;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function transport(string $path, ScriptedHttpClient $httpClient, array $config = []): SseHttpTransport
    {
        return new SseHttpTransport($config + ['url' => static::$baseUri . $path, 'timeout' => 5], $httpClient);
    }

    protected function expand(string $template): string
    {
        return str_replace(
            ['{base}', '{port}'],
            [static::$baseUri, (string) parse_url(static::$baseUri, PHP_URL_PORT)],
            $template,
        );
    }

    protected static function serverCommand(int $port): array
    {
        return ['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/sse_server.php'];
    }

    protected static function serverEnvironment(): array
    {
        // Open streams hold a worker each until they notice the client left.
        return ['PHP_CLI_SERVER_WORKERS' => '8'];
    }
}
