<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function serialize;
use function unserialize;

class FakeMcpTransportTest extends TestCase
{
    public function test_receive_returns_queued_responses_sequentially(): void
    {
        $first = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];
        $second = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]];

        $transport = new FakeMcpTransport($first, $second);

        $this->assertSame($first, $transport->receive());
        $this->assertSame($second, $transport->receive());
        $this->assertSame([$first, $second], $transport->getReceived());
    }

    public function test_empty_queue_throws_mcp_exception(): void
    {
        $transport = new FakeMcpTransport();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('response queue is empty');

        $transport->receive();
    }

    public function test_add_responses_extends_queue(): void
    {
        $transport = new FakeMcpTransport();
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];

        $this->assertSame($transport, $transport->addResponses($response));
        $this->assertSame($response, $transport->receive());
    }

    public function test_send_is_recorded(): void
    {
        $transport = new FakeMcpTransport();
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

        $transport->send($request);

        $this->assertSame([$request], $transport->getSent());
    }

    public function test_connection_state(): void
    {
        $transport = new FakeMcpTransport();
        $this->assertFalse($transport->isConnected());
        $transport->assertDisconnected();

        $transport->connect();
        $this->assertTrue($transport->isConnected());
        $transport->assertConnected();

        $transport->disconnect();
        $transport->assertDisconnected();
    }

    public function test_assert_protocol_version(): void
    {
        $transport = new FakeMcpTransport();
        $transport->setProtocolVersion('2025-11-25');

        $transport->assertProtocolVersion('2025-11-25');

        $this->expectException(AssertionFailedError::class);
        $transport->assertProtocolVersion('2024-11-05');
    }

    public function test_serialization_keeps_queue_and_recordings(): void
    {
        $initialize = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'];
        $remaining = ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]];

        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], $remaining);
        $transport->connect();
        $transport->setProtocolVersion('2025-11-25');
        $transport->send($initialize);
        $transport->receive();

        $restored = unserialize(serialize($transport));

        $this->assertInstanceOf(FakeMcpTransport::class, $restored);
        $this->assertSame([$initialize], $restored->getSent());
        $this->assertSame($transport->getReceived(), $restored->getReceived());
        $this->assertTrue($restored->isConnected());
        $restored->assertProtocolVersion('2025-11-25');
        $this->assertSame($remaining, $restored->receive());
    }

    public function test_count_assertions(): void
    {
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
        $transport->assertNothingSent();
        $transport->assertNothingReceived();

        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $transport->receive();

        $transport->assertSendCount(1);
        $transport->assertReceiveCount(1);

        $this->expectException(AssertionFailedError::class);
        $transport->assertNothingSent();
    }

    public function test_assert_sent_with_callback(): void
    {
        $transport = new FakeMcpTransport();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $transport->assertSent(fn (array $data): bool => $data['method'] === 'tools/list');

        $this->expectException(AssertionFailedError::class);
        $transport->assertSent(fn (array $data): bool => $data['method'] === 'tools/call');
    }

    public function test_mcp_method_assertions(): void
    {
        $transport = new FakeMcpTransport();
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $transport->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $transport->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $transport->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'search']]);
        $transport->send(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'search']]);

        $transport->assertInitialized();
        $transport->assertToolsListCalled();
        $transport->assertToolCalled('search', 2);
        $transport->assertMethodSent('tools/call', 2);

        $this->expectException(AssertionFailedError::class);
        $transport->assertToolCalled('missing');
    }
}
