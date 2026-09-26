<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP\Repro;

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
 * A plain file stands in for the event stream: its bytes arrive in the first read,
 * exactly as when a server writes several events in one TCP segment.
 */
class SseBufferedEventsReproTest extends TestCase
{
    protected string $stream;

    protected function setUp(): void
    {
        $this->stream = (string) tempnam(sys_get_temp_dir(), 'neuron-sse-');
    }

    protected function tearDown(): void
    {
        @unlink($this->stream);
    }

    protected function transport(): SseHttpTransport
    {
        return new SseHttpTransport(['url' => 'file://' . $this->stream, 'timeout' => 1], new ScriptedHttpClient(new HttpResponse(202, ''), new HttpResponse(202, '')));
    }

    public function test_a_response_that_arrived_with_the_endpoint_event_is_delivered(): void
    {
        file_put_contents($this->stream, "event: endpoint\ndata: /messages\n\n"
            . "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n");
        $transport = $this->transport();
        $transport->connect();

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $transport->receive());
    }

    public function test_the_second_of_two_messages_read_together_is_delivered_by_the_next_receive(): void
    {
        file_put_contents($this->stream, "event: endpoint\ndata: /messages\n\n");
        $transport = $this->transport();
        $transport->connect();

        file_put_contents($this->stream, "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\n"
            . "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n\n", FILE_APPEND);

        $this->assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/progress'], $transport->receive());
        $this->assertSame(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $transport->receive());
    }
}
