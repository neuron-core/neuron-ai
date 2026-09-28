<?php

declare(strict_types=1);

namespace Tests\MCP;

use NeuronAI\MCP\StdioTransport;
use PHPUnit\Framework\TestCase;

class StdioTransportTest extends TestCase
{
    public function testReceiveHandlesLargeLineDelimitedJsonResponses(): void
    {
        $serverPath = tempnam(sys_get_temp_dir(), 'neuron-mcp-stdio-test-') . '.php';
        file_put_contents($serverPath, <<<'PHP'
<?php

$tools = [];
for ($i = 0; $i < 200; $i++) {
    $tools[] = [
        'name' => 'tool_' . $i,
        'description' => str_repeat('Large description ', 20),
        'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
    ];
}

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (($request['method'] ?? null) === 'initialize') {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $request['id'],
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'test', 'version' => '1.0.0'],
            ],
        ]) . "\n";
        flush();
        continue;
    }

    if (($request['method'] ?? null) === 'tools/list') {
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => $request['id'],
            'result' => ['tools' => $tools],
        ]) . "\n";
        flush();
        continue;
    }
}
PHP);

        $transport = new StdioTransport([
            'command' => PHP_BINARY,
            'args' => [$serverPath],
        ]);

        $transport->connect();

        try {
            $transport->send([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => [],
            ]);

            $initializeResponse = $transport->receive();

            $this->assertSame(1, $initializeResponse['id']);

            $transport->send([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/list',
            ]);

            $toolsResponse = $transport->receive();

            $this->assertSame(2, $toolsResponse['id']);
            $this->assertCount(200, $toolsResponse['result']['tools']);
        } finally {
            $transport->disconnect();
            @unlink($serverPath);
        }
    }
}
