<?php

declare(strict_types=1);

namespace Tests\MCP;

use NeuronAI\MCP\CallableMcpTool;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function serialize;
use function unserialize;

class McpConnectorTest extends TestCase
{
    public McpConnector $connector;
    public FakeMcpTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->connector, $this->transport] = $this->createConnectorWithMockedClient();
    }

    /**
     * Create a connector with a mocked client to prevent HTTP calls
     * @return array<McpConnector | FakeMcpTransport>
     */
    private function createConnectorWithMockedClient(?McpClient $clientMock = null, array $extraResponses = []): array
    {
        $transport = new FakeMcpTransport(
            // Response for initialize request
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ...$extraResponses
        );

        $connector = new McpConnector([
            'transport' => $transport,
        ]);


        return [$connector, $transport];
    }

    public function testExcludeReturnsSelf(): void
    {
        $result = $this->connector->exclude(['tool1', 'tool2']);

        $this->assertSame($this->connector, $result);
    }

    public function testOnlyReturnsSelf(): void
    {
        $result = $this->connector->only(['tool1', 'tool2']);

        $this->assertSame($this->connector, $result);
    }

    public function testCallableMcpToolsIsSerializable(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        $callable = new CallableMcpTool(
            connector: $this->connector,
            item: $item,
        );

        // Test serialization
        $serialized = serialize($callable);
        $this->assertIsString($serialized);

        // Test unserialization
        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(CallableMcpTool::class, $unserialized);
    }

    public function testInvokeToolStaticMethodCreatesNewInstance(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        [$connector,] = $this->createConnectorWithMockedClient(extraResponses: [
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'The result is 42'],
                    ],
                ],
            ]
            ]);


        $reflection = new ReflectionClass($connector);
        $clientProperty = $reflection->getProperty('client');

        $clientMock = $this->createMock(McpClient::class);
        $clientMock->expects($this->once())
            ->method('callTool')
            ->with('test_tool', ['arg1' => 'value1'])
            ->willReturn([
                'result' => [
                    'content' => ['result' => 'success'],
                ],
            ]);

        $clientProperty->setValue($connector, $clientMock);

        $result = $connector->invokeTool(
            item: $item,
            arguments: ['arg1' => 'value1'],
        );

        $this->assertEquals(['result' => 'success'], $result);
    }

    public function testInvokeToolThrowsExceptionOnError(): void
    {
        [$connector,] = $this->createConnectorWithMockedClient(
            extraResponses: [
                [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'error' => [
                        'message' => 'Tool execution failed',
                    ],
                ]
            ]
        );

        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        $this->expectException(\NeuronAI\MCP\McpException::class);
        $this->expectExceptionMessage('Tool execution failed');

        $connector->invokeTool(
            item: $item,
            arguments: []
        );
    }

    public function testInvokeToolReturnsEmptyStringWhenNoContent(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        [$connector,$tranport] = $this->createConnectorWithMockedClient();

        $reflection = new ReflectionClass($connector);
        $clientProperty = $reflection->getProperty('client');

        $clientMock = $this->createMock(McpClient::class);
        $clientMock->expects($this->once())
            ->method('callTool')
            ->willReturn(['result' => []]);

        $clientProperty->setValue($connector, $clientMock);

        $result = $connector->invokeTool(
            item: $item,
            arguments: [],
        );

        $this->assertEquals('', $result);
    }

    public function testFakeTransportInitializesCorrectly(): void
    {
        $transport = new FakeMcpTransport(
            // Response for initialize request
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            // Response for tools/list request
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]
        );

        $connector = new McpConnector(['transport' => $transport]);
        $connector->tools();

        // Verify initialization sequence was called
        $transport->assertInitialized();
        $transport->assertToolsListCalled();
    }

    public function testFakeTransportToolsList(): void
    {
        $transport = new FakeMcpTransport(
            // Response for initialize request
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            // Response for tools/list request with multiple tools
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'calculator',
                            'description' => 'Perform calculations',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'operation' => ['type' => 'string'],
                                    'a' => ['type' => 'number'],
                                    'b' => ['type' => 'number'],
                                ],
                                'required' => ['operation', 'a', 'b'],
                            ],
                        ],
                        [
                            'name' => 'greet',
                            'description' => 'Greet someone',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                ],
                                'required' => ['name'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $connector = new McpConnector(['transport' => $transport]);
        $tools = $connector->tools();

        $this->assertCount(2, $tools);
        $transport->assertToolsListCalled();
    }

    public function testFakeTransportToolCalling(): void
    {
        [$connector,$transport] = $this->createConnectorWithMockedClient(
            extraResponses: [
                [
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'The result is 42'],
                    ],
                ],
            ]
            ]
        );

        $result = $connector->invokeTool(
            item: ['name' => 'calculator', 'description' => 'Calculator', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            arguments: ['operation' => 'add', 'a' => 20, 'b' => 22],
        );

        $this->assertEquals([['type' => 'text', 'text' => 'The result is 42']], $result);
        $transport->assertToolCalled('calculator', 1);
    }

    public function testFakeTransportOnlyAndExcludeFilters(): void
    {
        $transport = new FakeMcpTransport(
            // Response for initialize request
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            // Response for tools/list request
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'tools' => [
                        ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool3', 'description' => 'Tool 3', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                    ],
                ],
            ],
            [
                'jsonrpc' => '2.0',
                'id' => 3,
                'result' => [
                    'tools' => [
                        ['name' => 'tool1', 'description' => 'Tool 1', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool2', 'description' => 'Tool 2', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                        ['name' => 'tool3', 'description' => 'Tool 3', 'inputSchema' => ['type' => 'object', 'properties' => []]],
                    ],
                ],
            ]
        );

        $connector = new McpConnector(['transport' => $transport]);

        // Test "only" filter
        $toolsOnly = $connector->only(['tool1', 'tool3'])->tools();
        $this->assertCount(2, $toolsOnly);

        // Test "exclude" filter
        $toolsExclude = $connector->only([])->exclude(['tool2'])->tools();
        $this->assertCount(2, $toolsExclude);
    }
}
