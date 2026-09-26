<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StdioTransport;
use PHPUnit\Framework\TestCase;

use function microtime;

use const PHP_BINARY;

class StdioTransportTimingTest extends TestCase
{
    public function test_receive_honours_the_configured_timeout(): void
    {
        // A server that stays alive for 3s without ever answering.
        $transport = new StdioTransport(['command' => 'sleep', 'args' => ['3'], 'timeout' => 0.3]);
        $transport->connect();

        $start = microtime(true);
        try {
            $transport->receive();
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertSame('Timeout waiting for response from MCP server', $exception->getMessage());
            $this->assertLessThan(1.5, microtime(true) - $start);
        } finally {
            $transport->disconnect();
        }
    }

    public function test_disconnect_does_not_wait_longer_than_the_server_takes_to_exit(): void
    {
        // A server that exits as soon as its stdin closes.
        $transport = new StdioTransport(['command' => PHP_BINARY, 'args' => ['-r', 'while (fgets(STDIN) !== false) {}']]);
        $transport->connect();

        $start = microtime(true);
        $transport->disconnect();

        $this->assertLessThan(0.3, microtime(true) - $start);
    }
}
