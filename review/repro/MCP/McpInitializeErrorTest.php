<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

use function array_column;

class McpInitializeErrorTest extends TestCase
{
    public function test_a_rejected_initialize_fails_the_handshake_without_confirming_the_session(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'Unsupported protocol version']],
        );

        try {
            new McpClient(['transport' => $transport]);
            $this->fail('A rejected initialize must not produce a usable client.');
        } catch (McpException $exception) {
            $this->assertStringContainsString('Unsupported protocol version', $exception->getMessage());
        }

        $this->assertSame(['initialize'], array_column($transport->getSent(), 'method'));
    }
}
