<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpSessionLostException;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tests\MCP\Stub\ScriptedHttpClient;
use NeuronAI\Tests\MCP\Stub\SessionLosingMcpTransport;
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;
use Throwable;

use function array_map;
use function array_slice;
use function class_exists;
use function count;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getmypid;
use function json_decode;
use function json_encode;
use function pcntl_fork;
use function pcntl_waitpid;
use function posix_kill;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILE_APPEND;
use const JSON_UNESCAPED_SLASHES;
use const SIGKILL;

class McpClientTest extends TestCase
{
    public function test_initialize_handshake_sends_the_exact_protocol_messages(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $client = new McpClient(['transport' => $transport]);

        $transport->assertConnected();
        $this->assertSame([
            '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"neuron-ai","version":"1.0.0"}}}',
            // A notification: it carries no id and expects no answer.
            '{"jsonrpc":"2.0","method":"notifications/initialized"}',
        ], $this->wire($transport));
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

    public function test_an_expired_http_session_is_replaced_and_the_request_sent_again(): void
    {
        $requests = [];
        $initializations = 0;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (HttpRequest $request) use (&$requests, &$initializations): HttpResponse {
                $requests[] = $request;
                $message = json_decode($request->body, true);

                if ($message['method'] === 'initialize') {
                    $initializations++;
                    return new HttpResponse(200, $this->response($message, []), ['Mcp-Session-Id' => $initializations === 1 ? 'expired' : 'renewed']);
                }
                if (!isset($message['id'])) {
                    return new HttpResponse(202, '');
                }
                if ($request->headers['Mcp-Session-Id'] === 'expired') {
                    throw HttpException::statusError($request, new HttpResponse(404, ''));
                }

                return new HttpResponse(200, $this->response($message, ['tools' => []]));
            });

        $client = new McpClient(['url' => 'https://example.com/mcp'], $httpClient);

        $this->assertSame([], $client->listTools());
        $this->assertSame(
            ['initialize', 'notifications/initialized', 'tools/list', 'initialize', 'notifications/initialized', 'tools/list'],
            array_map(fn (HttpRequest $request): string => json_decode($request->body, true)['method'], $requests),
        );
        // The new session starts without the expired ID.
        $this->assertArrayNotHasKey('Mcp-Session-Id', $requests[3]->headers);
        $this->assertSame('renewed', $requests[5]->headers['Mcp-Session-Id']);
    }

    public function test_a_404_outside_a_session_is_not_retried(): void
    {
        $requests = 0;

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (HttpRequest $request) use (&$requests): HttpResponse {
                $requests++;
                throw HttpException::statusError($request, new HttpResponse(404, ''));
            });

        try {
            new McpClient(['url' => 'https://example.com/mcp'], $httpClient);
            $this->fail('A 404 before any session exists must fail the handshake.');
        } catch (McpException $e) {
            $this->assertNotInstanceOf(McpSessionLostException::class, $e);
        }

        $this->assertSame(1, $requests);
    }

    public function test_an_sse_response_may_deliver_notifications_before_the_response(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')
            ->willReturnCallback(function (HttpRequest $request): HttpResponse {
                $message = json_decode($request->body, true);

                return match ($message['method']) {
                    'initialize' => new HttpResponse(200, $this->response($message, [])),
                    'notifications/initialized' => new HttpResponse(202, ''),
                    default => new HttpResponse(
                        200,
                        "event: message\ndata: " . json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progress' => 1]]) . "\n\n"
                        . "event: message\ndata: " . $this->response($message, ['tools' => [['name' => 'search']]]) . "\n\n",
                        ['Content-Type' => 'text/event-stream'],
                    ),
                };
            });

        $client = new McpClient(['url' => 'https://example.com/mcp'], $httpClient);

        $this->assertSame([['name' => 'search']], $client->listTools());
    }

    public function test_an_application_transport_is_kept_in_forked_children(): void
    {
        if (!function_exists('pcntl_fork') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Forking requires the pcntl extension and spatie/fork.');
        }

        // Its lifetime is the application's: a child opens no session of its own on it.
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'parent']]]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => [['type' => 'text', 'text' => 'child']]]],
        );
        $client = new McpClient(['transport' => $transport]);
        $client->callTool('echo');

        [$text] = Fork::new()->run(function () use ($client): string {
            try {
                return $client->callTool('echo')['result']['content'][0]['text'];
            } catch (Throwable $e) {
                return $e::class . ': ' . $e->getMessage();
            }
        });

        $this->assertSame('child', $text);
    }

    public function test_only_the_process_that_opened_the_session_ends_it(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('Forking requires the pcntl and posix extensions.');
        }

        $log = tempnam(sys_get_temp_dir(), 'neuron-mcp-disconnects');
        $transport = new class ($log, ['jsonrpc' => '2.0', 'id' => 1, 'result' => []]) extends FakeMcpTransport {
            /**
             * @param array<string, mixed> ...$responses
             */
            public function __construct(protected string $log, array ...$responses)
            {
                parent::__construct(...$responses);
            }

            public function disconnect(): void
            {
                file_put_contents($this->log, getmypid() . "\n", FILE_APPEND);
                parent::disconnect();
            }
        };
        $client = new McpClient(['transport' => $transport]);

        try {
            $child = pcntl_fork();
            if ($child === 0) {
                try {
                    // A forked worker ends: its copy of the client is destroyed.
                    unset($client);
                } finally {
                    posix_kill(getmypid(), SIGKILL);
                }
            }
            pcntl_waitpid($child, $status);

            $this->assertSame('', file_get_contents($log));

            unset($client);
            $this->assertSame(getmypid() . "\n", file_get_contents($log));
        } finally {
            // Destroyed first, a client left by a failed assertion would log to the file again.
            unset($client, $transport);
            unlink($log);
        }
    }

    public function test_a_config_without_a_transport_is_rejected(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Transport not supported! Provide either "command" for StdioTransport, "url" for StreamableHttpTransport/SseHttpTransport, or a custom "transport" instance.');

        new McpClient(['token' => 'secret', 'timeout' => 5]);
    }

    public function test_a_custom_transport_takes_precedence_over_command_and_url(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        new McpClient(['transport' => $transport, 'command' => '/nonexistent/mcp-server', 'url' => 'https://example.com/mcp']);

        $transport->assertInitialized();
    }

    public function test_a_url_selects_the_streamable_http_transport_with_the_given_client(): void
    {
        $httpClient = new ScriptedHttpClient(
            new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":{}}'),
            new HttpResponse(202, ''),
        );

        new McpClient(['url' => 'https://example.com/mcp'], $httpClient);

        $this->assertCount(2, $httpClient->requests);
        $this->assertSame('https://example.com/mcp', $httpClient->requests[0]->uri);
    }

    public function test_an_async_url_selects_the_legacy_sse_transport(): void
    {
        $httpClient = new ScriptedHttpClient();

        try {
            new McpClient(['url' => 'http://127.0.0.1:9/sse', 'async' => true, 'timeout' => 1], $httpClient);
            $this->fail('An unreachable SSE endpoint must fail the handshake');
        } catch (McpException $exception) {
            $this->assertSame('Failed to open SSE connection to: http://127.0.0.1:9/sse', $exception->getMessage());
        }

        // The SSE stream is opened before any request is posted.
        $this->assertSame([], $httpClient->requests);
    }

    public function test_requests_carry_increasing_ids_and_omit_empty_params(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => []]],
        );
        $client = new McpClient(['transport' => $transport]);

        $client->listTools();
        $client->callTool('search', ['query' => 'neuron']);

        $this->assertSame([
            '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
            '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"search","arguments":{"query":"neuron"}}}',
        ], array_slice($this->wire($transport), 2));
    }

    public function test_list_tools_follows_pagination_cursors_until_the_last_page(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'a'], ['name' => 'b']], 'nextCursor' => 'page/2?é']],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['tools' => [], 'nextCursor' => 'page-3']],
            ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['tools' => [['name' => 'c']], 'nextCursor' => null]],
        );
        $client = new McpClient(['transport' => $transport]);

        $this->assertSame([['name' => 'a'], ['name' => 'b'], ['name' => 'c']], $client->listTools());

        $sent = $transport->getSent();
        $this->assertArrayNotHasKey('params', $sent[2]);
        $this->assertSame(['cursor' => 'page/2?é'], $sent[3]['params']);
        $this->assertSame(['cursor' => 'page-3'], $sent[4]['params']);
        $transport->assertToolsListCalled(3);
    }

    public function test_only_the_response_to_the_pending_request_is_returned(): void
    {
        $response = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'mine']]]];
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            // A late answer to an earlier, abandoned request.
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => 'stale']]]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['progress' => 50]],
            // A server request reusing the client's pending id must not pass for the response.
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'sampling/createMessage', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => '2', 'result' => ['content' => [['type' => 'text', 'text' => 'forged']]]],
            $response,
        );
        $client = new McpClient(['transport' => $transport]);

        $this->assertSame($response, $client->callTool('echo'));

        // Server requests go unanswered: only the handshake and the call were sent.
        $transport->assertSendCount(3);
    }

    public function test_call_tool_drops_null_arguments_but_keeps_other_falsy_values(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => []],
        );

        (new McpClient(['transport' => $transport]))->callTool('search', [
            'query' => 'x',
            'limit' => null,
            'exact' => false,
            'page' => 0,
            'filter' => '',
            'tags' => [],
        ]);

        $this->assertSame(
            '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search","arguments":{"query":"x","exact":false,"page":0,"filter":"","tags":[]}}}',
            $this->wire($transport)[2],
        );
    }

    public function test_call_tool_without_arguments_sends_an_empty_object(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => []],
        );
        $client = new McpClient(['transport' => $transport]);

        $client->callTool('now');
        $client->callTool('now', ['timezone' => null]);

        // An object, never a JSON list: servers validate arguments against an object schema.
        $this->assertSame('{"name":"now","arguments":{}}', json_encode($transport->getSent()[2]['params']));
        $this->assertSame('{"name":"now","arguments":{}}', json_encode($transport->getSent()[3]['params']));
    }

    public function test_a_lost_session_is_reopened_and_the_request_sent_once_more(): void
    {
        $transport = (new SessionLosingMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['protocolVersion' => '2025-06-18']],
            ['jsonrpc' => '2.0', 'id' => 4, 'result' => ['tools' => [['name' => 'search']]]],
        ))->loseSessionOn('tools/list');
        $client = new McpClient(['transport' => $transport]);

        $this->assertSame([['name' => 'search']], $client->listTools());

        $this->assertSame(2, $transport->connections);
        $this->assertSame(
            ['initialize', 'notifications/initialized', 'tools/list', 'initialize', 'notifications/initialized', 'tools/list'],
            array_map(fn (array $message): string => $message['method'], $transport->getSent()),
        );
        $transport->assertProtocolVersion('2025-06-18');
    }

    public function test_a_session_lost_again_after_reopening_is_not_retried_forever(): void
    {
        $transport = (new SessionLosingMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => []],
        ))->loseSessionOn('tools/call', times: 2);
        $client = new McpClient(['transport' => $transport]);

        try {
            $client->callTool('delete_everything');
            $this->fail('A second lost session must reach the caller');
        } catch (McpSessionLostException) {
            $transport->assertToolCalled('delete_everything', 2);
            $this->assertSame(2, $transport->connections);
        }
    }

    public function test_a_failure_after_delivery_is_not_retried(): void
    {
        // The request may have run on the server: sending it again could run it twice.
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
        $client = new McpClient(['transport' => $transport]);

        try {
            $client->callTool('charge_card');
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertNotInstanceOf(McpSessionLostException::class, $exception);
        }

        $transport->assertToolCalled('charge_card', 1);
        $transport->assertMethodSent('initialize', 1);
    }

    public function test_destroying_the_client_ends_its_session(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
        $client = new McpClient(['transport' => $transport]);
        $transport->assertConnected();

        unset($client);

        $transport->assertDisconnected();
    }

    /**
     * @return list<string>
     */
    protected function wire(FakeMcpTransport $transport): array
    {
        return array_map(fn (array $message): string => json_encode($message, JSON_UNESCAPED_SLASHES), $transport->getSent());
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     */
    protected function response(array $request, array $result): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
    }
}
