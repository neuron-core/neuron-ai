<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpConnector;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpTool;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tests\MCP\Stub\ScriptedHttpClient;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;
use function json_encode;
use function serialize;
use function unserialize;

class McpConnectorTest extends TestCase
{
    protected McpConnector $connector;

    protected FakeMcpTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeMcpTransport(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
        $this->connector = new McpConnector(['transport' => $this->transport]);
    }

    public function test_custom_http_client_is_used_for_http_transport(): void
    {
        $httpClient = new ScriptedHttpClient(
            new HttpResponse(200, '{"jsonrpc":"2.0","id":1,"result":[]}'),
            new HttpResponse(202, ''),
            new HttpResponse(200, '{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}'),
        );

        $connector = new McpConnector(
            config: [
                'url' => 'https://example.com/mcp',
                'timeout' => 15,
            ],
            httpClient: $httpClient,
        );

        $this->assertSame([], $connector->tools());
        $this->assertCount(3, $httpClient->requests);
        foreach ($httpClient->requests as $request) {
            $this->assertSame('https://example.com/mcp', $request->uri);
            $this->assertSame(15.0, $request->timeout);
            $this->assertSame('application/json, text/event-stream', $request->headers['Accept']);
            $this->assertSame('application/json', $request->headers['Content-Type']);
        }
    }

    public function test_the_session_opens_lazily_on_first_use(): void
    {
        $this->transport->assertNothingSent();

        $this->listTools([]);
        $this->connector->tools();

        $this->transport->assertInitialized();
    }

    public function test_listed_tools_become_neuron_tools(): void
    {
        $this->listTools([
            [
                'name' => 'calculator',
                'description' => 'Perform calculations',
                'annotations' => ['readOnlyHint' => true, 'title' => 'Calculator'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'operation' => ['type' => 'string', 'description' => 'The operator', 'enum' => ['add', 'sub']],
                        'a' => ['type' => 'number'],
                        'b' => ['type' => 'integer'],
                        'verbose' => ['type' => 'boolean'],
                    ],
                    'required' => ['operation', 'a'],
                ],
            ],
            ['name' => 'ping'],
        ]);

        [$calculator, $ping] = $this->connector->tools();

        $this->assertInstanceOf(McpTool::class, $calculator);
        $this->assertSame('calculator', $calculator->getName());
        $this->assertSame('Perform calculations', $calculator->getDescription());
        $this->assertSame(['readOnlyHint' => true, 'title' => 'Calculator'], $calculator->getAnnotations());
        $this->assertSame(
            [
                ['operation', PropertyType::STRING, true, 'The operator'],
                ['a', PropertyType::NUMBER, true, null],
                ['b', PropertyType::INTEGER, false, null],
                ['verbose', PropertyType::BOOLEAN, false, null],
            ],
            array_map(
                fn (ToolPropertyInterface $property): array => [$property->getName(), $property->getType(), $property->isRequired(), $property->getDescription()],
                $calculator->getProperties(),
            ),
        );
        $operation = $calculator->getProperties()[0];
        $this->assertInstanceOf(ToolProperty::class, $operation);
        $this->assertSame(['add', 'sub'], $operation->getEnum());
        $this->assertSame(['operation', 'a'], $calculator->getRequiredProperties());

        // A tool without description, annotations or input schema takes no arguments.
        $this->assertInstanceOf(McpTool::class, $ping);
        $this->assertSame('ping', $ping->getName());
        $this->assertNull($ping->getDescription());
        $this->assertSame([], $ping->getAnnotations());
        $this->assertSame([], $ping->getProperties());
    }

    public function test_tool_schema_builds_nested_properties_and_enum_array_items(): void
    {
        $this->listTools([[
            'name' => 'select',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['selection' => [
                    'type' => ['object', 'null'],
                    'properties' => ['labels' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'items' => ['type' => 'string', 'enum' => ['primary', 'secondary']],
                    ]],
                    'required' => ['labels'],
                ]],
                'required' => ['selection'],
            ],
        ]]);

        $tools = $this->connector->tools();
        $selection = $tools[0]->getProperties()[0];
        $this->assertInstanceOf(ObjectProperty::class, $selection);
        $this->assertTrue($selection->isRequired());
        $this->assertTrue($selection->isNullable());
        $labels = $selection->getProperties()[0];
        $this->assertInstanceOf(ArrayProperty::class, $labels);
        $this->assertTrue($labels->isRequired());
        $this->assertSame(1, $labels->getJsonSchema()['minItems']);
        $item = $labels->getItems();
        $this->assertInstanceOf(ToolProperty::class, $item);
        $this->assertSame(PropertyType::STRING, $item->getType());
        $this->assertSame(['primary', 'secondary'], $item->getEnum());
    }

    public function test_only_keeps_the_listed_tools(): void
    {
        $this->listTools([['name' => 'tool1'], ['name' => 'tool2'], ['name' => 'tool3']]);

        $this->assertSame($this->connector, $this->connector->only(['tool3', 'tool1', 'unknown']));

        $this->assertSame(['tool1', 'tool3'], $this->toolNames());
    }

    public function test_exclude_drops_the_listed_tools(): void
    {
        $this->listTools([['name' => 'tool1'], ['name' => 'tool2'], ['name' => 'tool3']]);

        $this->assertSame($this->connector, $this->connector->exclude(['tool2']));

        $this->assertSame(['tool1', 'tool3'], $this->toolNames());
    }

    public function test_exclude_wins_over_only(): void
    {
        $this->listTools([['name' => 'read'], ['name' => 'write'], ['name' => 'delete']]);

        $this->connector->only(['read', 'delete'])->exclude(['delete']);

        $this->assertSame(['read'], $this->toolNames());
    }

    public function test_filters_match_tool_names_exactly(): void
    {
        $this->listTools([['name' => 'Delete'], ['name' => 'delete '], ['name' => 'delete']]);

        $this->connector->exclude(['delete']);

        $this->assertSame(['Delete', 'delete '], $this->toolNames());
    }

    public function test_an_empty_only_list_keeps_every_tool(): void
    {
        $this->listTools([['name' => 'tool1'], ['name' => 'tool2']]);

        $this->connector->only(['tool1'])->only([]);

        $this->assertSame(['tool1', 'tool2'], $this->toolNames());
    }

    public function test_executing_a_tool_forwards_its_declared_inputs_to_the_server(): void
    {
        $content = [['type' => 'text', 'text' => 'Found 3 results'], ['type' => 'image', 'data' => 'iVBORw0KGgo=', 'mimeType' => 'image/png']];
        $this->listTools([[
            'name' => 'search',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['query' => ['type' => 'string'], 'limit' => ['type' => 'integer']],
                'required' => ['query'],
            ],
        ]]);
        $this->transport->addResponses(['jsonrpc' => '2.0', 'id' => 3, 'result' => ['content' => $content]]);
        $tool = $this->connector->tools()[0];

        $tool->setInputs(['query' => 'neuron', 'undeclared' => 'dropped'])->execute();

        // Optional inputs the model left out are not sent, nor are inputs the schema does not declare.
        $this->transport->assertSent(fn (array $message): bool => ($message['method'] ?? null) === 'tools/call'
            && json_encode($message['params']) === '{"name":"search","arguments":{"query":"neuron"}}');
        $this->assertSame(json_encode($content), $tool->getResult());
    }

    public function test_invoke_tool_returns_the_result_content(): void
    {
        $this->transport->addResponses([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => [['type' => 'text', 'text' => 'The result is 42']]],
        ]);

        $result = $this->connector->invokeTool(
            item: ['name' => 'calculator', 'inputSchema' => ['type' => 'object', 'properties' => []]],
            arguments: ['operation' => 'add', 'a' => 20, 'b' => 22],
        );

        $this->assertSame([['type' => 'text', 'text' => 'The result is 42']], $result);
        $this->transport->assertSent(fn (array $message): bool => ($message['params']['arguments'] ?? null) === ['operation' => 'add', 'a' => 20, 'b' => 22]);
    }

    public function test_a_tool_error_result_reaches_the_model_as_content(): void
    {
        // A tool failure is feedback the model can act on, unlike a protocol error.
        $content = [['type' => 'text', 'text' => 'Rate limit exceeded, retry later']];
        $this->transport->addResponses(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => $content, 'isError' => true]]);

        $this->assertSame($content, $this->connector->invokeTool(['name' => 'search'], []));
    }

    public function test_invoke_tool_throws_the_protocol_error_message(): void
    {
        $this->transport->addResponses([
            'jsonrpc' => '2.0',
            'id' => 2,
            'error' => ['code' => -32602, 'message' => 'Unknown tool: invalid_tool_name'],
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Unknown tool: invalid_tool_name');

        $this->connector->invokeTool(item: ['name' => 'invalid_tool_name'], arguments: []);
    }

    public function test_invoke_tool_returns_an_empty_string_without_content(): void
    {
        $this->transport->addResponses(
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['structuredContent' => ['ok' => true]]],
        );

        $withoutResultContent = $this->connector->invokeTool(['name' => 'noop'], []);
        $withStructuredContentOnly = $this->connector->invokeTool(['name' => 'noop'], []);

        $this->assertSame(['', ''], [$withoutResultContent, $withStructuredContentOnly]);
    }

    public function test_with_configures_the_named_tool(): void
    {
        $this->listTools([['name' => 'search'], ['name' => 'delete']]);

        [$search, $delete] = $this->connector
            ->with('search', fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(3))
            ->with('delete', function (Tool $tool): void {
                $tool->requireApproval();
            })
            ->with('not-listed', function (): void {
                $this->fail('A callback for a tool the server does not list must not run');
            })
            ->tools();

        $this->assertSame(3, $search->getMaxRuns());
        $this->assertFalse($search->requiresApproval());
        $this->assertNull($delete->getMaxRuns());
        $this->assertTrue($delete->requiresApproval());
    }

    public function test_with_may_replace_the_tool(): void
    {
        $this->listTools([['name' => 'search']]);
        $replacement = new class () extends Tool {
            protected string $name = 'guarded_search';

            public function __invoke(): string
            {
                return 'guarded';
            }
        };

        $tools = $this->connector->with('search', fn (ToolInterface $tool): ToolInterface => $replacement)->tools();

        $this->assertSame([$replacement], $tools);
    }

    public function test_with_callbacks_do_not_break_tool_serialization(): void
    {
        $this->listTools([['name' => 'search']]);

        $tool = $this->connector
            ->with('search', fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(3))
            ->tools()[0];

        $unserialized = unserialize(serialize($tool));

        $this->assertInstanceOf(McpTool::class, $unserialized);
        $this->assertSame('search', $unserialized->getName());
        $this->assertSame(3, $unserialized->getMaxRuns());
    }

    public function test_an_unserialized_tool_calls_the_server_through_a_new_session(): void
    {
        $this->listTools([['name' => 'search', 'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]]]);
        // Answers for the session the unserialized connector opens on its copy of the transport.
        $this->transport->addResponses(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'after resume']]]],
        );
        $tool = unserialize(serialize($this->connector->tools()[0]));

        $tool->setInputs(['query' => 'neuron'])->execute();

        $this->assertSame('[{"type":"text","text":"after resume"}]', $tool->getResult());
        $this->transport->assertToolCalled('search', 0);
    }

    public function test_an_unserialized_connector_keeps_its_filters_but_not_its_callbacks(): void
    {
        $this->listTools([['name' => 'read'], ['name' => 'write'], ['name' => 'delete']]);
        $this->connector
            ->only(['read', 'delete'])
            ->exclude(['delete'])
            ->with('read', fn (ToolInterface $tool): ToolInterface => $tool->setMaxRuns(1));

        $restored = unserialize(serialize($this->connector));
        $tools = $restored->tools();

        $this->assertSame(['read'], array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools));
        $this->assertNull($tools[0]->getMaxRuns());
    }

    /**
     * @param list<array<string, mixed>> $tools
     */
    protected function listTools(array $tools): void
    {
        $this->transport->addResponses(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => $tools]]);
    }

    /**
     * @return list<string>
     */
    protected function toolNames(): array
    {
        return array_values(array_map(fn (ToolInterface $tool): string => $tool->getName(), $this->connector->tools()));
    }
}
