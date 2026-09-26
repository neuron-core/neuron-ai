<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

class McpListToolsErrorTest extends TestCase
{
    public function test_a_protocol_error_on_tools_list_becomes_an_mcp_exception(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32601, 'message' => 'Method not found']],
        );
        $client = new McpClient(['transport' => $transport]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Method not found');

        $client->listTools();
    }

    public function test_a_protocol_error_on_a_later_tools_list_page_becomes_an_mcp_exception(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [['name' => 'search']], 'nextCursor' => 'page-2']],
            ['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32602, 'message' => 'Invalid cursor']],
        );
        $client = new McpClient(['transport' => $transport]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Invalid cursor');

        $client->listTools();
    }

    public function test_connector_tools_surfaces_the_server_error_as_an_mcp_exception(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32603, 'message' => 'Internal error']],
        );
        $connector = McpConnector::make(['transport' => $transport]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Internal error');

        $connector->tools();
    }
}
