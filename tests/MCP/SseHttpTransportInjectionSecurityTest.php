<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\Tests\MCP\Stub\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const FILE_APPEND;

/**
 * The event stream is untrusted input: only an "endpoint" event may say where
 * requests (and their credentials) are posted, and only "message" events carry
 * JSON-RPC answers. A plain file stands in for the stream, so the exact bytes
 * the server sends are scripted without a network.
 */
class SseHttpTransportInjectionSecurityTest extends TestCase
{
    protected const ANSWER = '{"jsonrpc":"2.0","id":1,"result":{"source":"message event"}}';

    protected string $stream;

    protected ScriptedHttpClient $httpClient;

    protected function setUp(): void
    {
        $this->stream = (string) tempnam(sys_get_temp_dir(), 'neuron-sse-');
        $this->httpClient = new ScriptedHttpClient(new HttpResponse(202, ''), new HttpResponse(202, ''));
    }

    protected function tearDown(): void
    {
        @unlink($this->stream);
    }

    protected function connectedTransport(string $handshake): SseHttpTransport
    {
        file_put_contents($this->stream, $handshake);
        $transport = new SseHttpTransport(['url' => 'file://' . $this->stream, 'timeout' => 1], $this->httpClient);
        $transport->connect();

        return $transport;
    }

    protected function serverSends(string $events): void
    {
        file_put_contents($this->stream, $events, FILE_APPEND);
    }

    public function test_only_the_endpoint_event_announces_where_requests_are_posted(): void
    {
        $transport = $this->connectedTransport(
            "event: message\ndata: /hijacked-by-message\n\n"
            . "data: /hijacked-by-untyped-event\n\n"
            . "event: endpoint\ndata: /messages\n\n"
        );

        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);

        $this->assertSame('file:///messages', $this->httpClient->requests[0]->uri);
    }

    public function test_answers_are_read_only_from_message_events(): void
    {
        $transport = $this->connectedTransport("event: endpoint\ndata: /messages\n\n");
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->serverSends(
            "event: endpoint\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"source\":\"endpoint event\"}}\n\n"
            . "event: rpc\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"source\":\"custom event\"}}\n\n"
            . 'event: message' . "\ndata: " . self::ANSWER . "\n\n"
        );

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['source' => 'message event']], $transport->receive());
    }

    public function test_an_endpoint_announced_after_the_handshake_does_not_redirect_requests(): void
    {
        $transport = $this->connectedTransport("event: endpoint\ndata: /messages\n\n");
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->serverSends("event: endpoint\ndata: /hijacked\n\nevent: message\ndata: " . self::ANSWER . "\n\n");
        $transport->receive();
        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled']);

        $this->assertSame(['file:///messages', 'file:///messages'], [
            $this->httpClient->requests[0]->uri,
            $this->httpClient->requests[1]->uri,
        ]);
    }
}
