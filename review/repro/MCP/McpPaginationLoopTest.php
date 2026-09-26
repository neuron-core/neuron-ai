<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;

class McpPaginationLoopTest extends TestCase
{
    public function test_a_server_repeating_its_cursor_cannot_keep_the_client_paging_forever(): void
    {
        $pages = [];
        for ($id = 2; $id <= 101; $id++) {
            $pages[] = ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['tools' => [['name' => "tool{$id}"]], 'nextCursor' => 'same']];
        }
        $transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []], ...$pages);
        $client = new McpClient(['transport' => $transport]);

        try {
            $client->listTools();
            $this->fail('listTools() accepted an endless tools/list pagination');
        } catch (McpException $exception) {
            $this->assertStringNotContainsString('response queue is empty', $exception->getMessage());
        }

        $listRequests = array_values(array_filter(
            $transport->getSent(),
            fn (array $message): bool => ($message['method'] ?? null) === 'tools/list'
        ));
        $this->assertCount(2, $listRequests, 'The client kept following a repeated cursor');
    }
}
