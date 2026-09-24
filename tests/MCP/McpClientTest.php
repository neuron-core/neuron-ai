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
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;
use Throwable;

use function array_map;
use function class_exists;
use function count;
use function function_exists;
use function json_decode;
use function json_encode;

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

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $result
     */
    protected function response(array $request, array $result): string
    {
        return json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result]);
    }
}
