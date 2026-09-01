<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpTool;
use NeuronAI\Testing\FakeMcpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function serialize;
use function unserialize;
use function count;

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

    public function test_exclude_returns_self(): void
    {
        $result = $this->connector->exclude(['tool1', 'tool2']);

        $this->assertSame($this->connector, $result);
    }

    public function test_only_returns_self(): void
    {
        $result = $this->connector->only(['tool1', 'tool2']);

        $this->assertSame($this->connector, $result);
    }

    public function test_custom_http_client_is_used_for_http_transport(): void
    {
        $requests = [];

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects($this->once())
            ->method('withHeaders')
            ->with([
                'Accept' => 'application/json, text/event-stream',
                'Content-Type' => 'application/json',
                'User-Agent' => 'neuron-ai/1.0.0',
            ])
            ->willReturnSelf();
        $httpClient->expects($this->once())
            ->method('withTimeout')
            ->with(15.0)
            ->willReturnSelf();
        $httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (HttpRequest $request) use (&$requests): HttpResponse {
                $requests[] = $request;
                return match (count($requests)) {
                    1 => new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":[]}'),
                    2 => new HttpResponse(202, ''),
                    default => new HttpResponse(200, '{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}'),
                };
            });

        $connector = new McpConnector(
            config: [
                'url' => 'https://example.com/mcp',
                'timeout' => 15,
            ],
            httpClient: $httpClient,
        );

        $this->assertSame([], $connector->tools());
        $this->assertCount(3, $requests);
        $this->assertSame('https://example.com/mcp', $requests[0]->uri);
    }

    public function test_mcp_tools_are_serializable(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        $tool = new McpTool(
            name: $item['name'],
            description: $item['description'],
            annotations: [],
            connector: $this->connector,
            item: $item,
        );

        $serialized = serialize($tool);
        $unserialized = unserialize($serialized);
        $this->assertInstanceOf(McpTool::class, $unserialized);
        $this->assertSame('test_tool', $unserialized->getName());
    }

    public function test_invoke_tool_static_method_creates_new_instance(): void
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
            ],
            ]);


        $reflection = new ReflectionClass($connector);
        $clientProperty = $reflection->getProperty('client');

        $fakeTransport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $clientMock = $this->createMock(McpClient::class);
        $clientMock->expects($this->once())
            ->method('callTool')
            ->with('test_tool', ['arg1' => 'value1'])
            ->willReturn([
                'result' => [
                    'content' => ['result' => 'success'],
                ],
            ]);

        $transportProperty = (new ReflectionClass(McpClient::class))->getProperty('transport');
        $transportProperty->setValue($clientMock, $fakeTransport);

        $clientProperty->setValue($connector, $clientMock);

        $result = $connector->invokeTool(
            item: $item,
            arguments: ['arg1' => 'value1'],
        );

        $this->assertEquals(['result' => 'success'], $result);
    }

    public function test_invoke_tool_throws_exception_on_error(): void
    {
        [$connector,] = $this->createConnectorWithMockedClient(
            extraResponses: [
                [
                    'jsonrpc' => '2.0',
                    'id' => 2,
                    'error' => [
                        'message' => 'Tool execution failed',
                    ],
                ],
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

    public function test_invoke_tool_returns_empty_string_when_no_content(): void
    {
        $item = [
            'name' => 'test_tool',
            'description' => 'Test tool',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];

        [$connector,$tranport] = $this->createConnectorWithMockedClient();

        $reflection = new ReflectionClass($connector);
        $clientProperty = $reflection->getProperty('client');

        $fakeTransport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $clientMock = $this->createMock(McpClient::class);
        $clientMock->expects($this->once())
            ->method('callTool')
            ->willReturn(['result' => []]);

        $transportProperty = (new ReflectionClass(McpClient::class))->getProperty('transport');
        $transportProperty->setValue($clientMock, $fakeTransport);

        $clientProperty->setValue($connector, $clientMock);

        $result = $connector->invokeTool(
            item: $item,
            arguments: [],
        );

        $this->assertEquals('', $result);
    }

    public function test_fake_transport_initializes_correctly(): void
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

    public function test_fake_transport_tools_list(): void
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

    public function test_fake_transport_tool_calling(): void
    {
        [$connector,$transport] = $this->createConnectorWithMockedClient(
            extraResponses: [
                [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'content' => [
                        ['type' => 'text', 'text' => 'The result is 42'],
                    ],
                ],
            ],
            ]
        );

        $result = $connector->invokeTool(
            item: ['name' => 'calculator', 'description' => 'Calculator', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            arguments: ['operation' => 'add', 'a' => 20, 'b' => 22],
        );

        $this->assertEquals([['type' => 'text', 'text' => 'The result is 42']], $result);
        $transport->assertToolCalled('calculator', 1);
    }

    public function test_fake_transport_only_and_exclude_filters(): void
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
