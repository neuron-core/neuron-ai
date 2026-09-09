<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function count;

class ToolSearchMiddlewareTest extends TestCase
{
    private function createTool(string $name, string $description): Tool
    {
        return new class ($name, $description) extends Tool {
            public function __construct(string $name, string $description)
            {
                $this->name = $name;
                $this->description = $description;
            }

            public function __invoke(): string
            {
                return 'file contents';
            }
        };
    }

    private function createMiddleware(array $toolPool): ToolSearchMiddleware
    {
        return new ToolSearchMiddleware($toolPool);
    }

    // --- before() tests ---

    public function test_before_injects_tool_search_into_inference_event(): void
    {
        $middleware = $this->createMiddleware([]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $event = new AIInferenceEvent();
        $node = new ToolNode(new InMemoryChatHistory());

        $middleware->before($node, $event, $state);

        $this->assertCount(1, $state->request->tools);
        $this->assertInstanceOf(ToolSearchTool::class, $state->request->tools[0]);
    }

    public function test_before_does_modify_instructions(): void
    {
        $middleware = $this->createMiddleware([]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('original instructions'), []);
        $event = new AIInferenceEvent();
        $node = new ToolNode(new InMemoryChatHistory());

        $middleware->before($node, $event, $state);

        $this->assertStringContainsString('tool_search', $state->request->instructions->getContent());
    }

    public function test_before_skips_non_inference_event(): void
    {
        $middleware = $this->createMiddleware([]);
        $toolCallMessage = new ToolCallMessage(null, [ToolCall::make('test', 'call_1')]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $inferenceEvent = new AIInferenceEvent();
                $toolCallEvent = new ToolCallEvent($toolCallMessage);
        $node = new ToolNode(new InMemoryChatHistory());

        $originalInstructions = $state->request->instructions->getContent();
        $middleware->before($node, $toolCallEvent, $state);

        // Instructions should not have been modified
        $this->assertSame($originalInstructions, $state->request->instructions->getContent());
    }

    public function test_before_does_not_duplicate_tool_search(): void
    {
        $middleware = $this->createMiddleware([]);
        $existing = new ToolSearchTool([]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), [$existing]);
        $event = new AIInferenceEvent();
        $node = new ToolNode(new InMemoryChatHistory());

        $middleware->before($node, $event, $state);

        $count = 0;
        foreach ($state->request->tools as $tool) {
            if ($tool instanceof ToolSearchTool) {
                $count++;
            }
        }
        $this->assertSame(1, $count);
    }

    public function test_before_preserves_existing_tools(): void
    {
        $existingTool = $this->createTool('existing', 'An existing tool');
        $middleware = $this->createMiddleware([]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), [$existingTool]);
        $event = new AIInferenceEvent();
        $node = new ToolNode(new InMemoryChatHistory());

        $middleware->before($node, $event, $state);

        $names = array_map(fn (ToolInterface $t): string => $t->getName(), $state->request->tools);
        $this->assertContains('existing', $names);
        $this->assertContains('tool_search', $names);
    }

    // --- after() tests ---

    public function test_after_injects_discovered_tools_into_event(): void
    {
        $dbTool = $this->createTool('query_database', 'Execute SQL queries');
        $middleware = $this->createMiddleware([$dbTool]);
        $node = new ToolNode(new InMemoryChatHistory());

        $toolResultMessage = new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => 'database'])->setResult('found'),
        ]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $event = new AIInferenceEvent();
        $state->request->messages = [$toolResultMessage];

        $middleware->after($node, $event, $state);

        $this->assertCount(1, $state->request->tools);
        $this->assertSame('query_database', $state->request->tools[0]->getName());
    }

    public function test_after_deduplicates_by_tool_name(): void
    {
        $dbTool = $this->createTool('query_database', 'Execute SQL queries');
        $middleware = $this->createMiddleware([$dbTool]);
        $node = new ToolNode(new InMemoryChatHistory());

        $existingDbTool = $this->createTool('query_database', 'Execute SQL queries');
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), [$existingDbTool]);
        $event = new AIInferenceEvent();

        $toolResultMessage = new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => 'database'])->setResult('found'),
        ]);
        $state->request->messages = [$toolResultMessage];

        $middleware->after($node, $event, $state);

        $names = array_map(fn (ToolInterface $t): string => $t->getName(), $state->request->tools);
        $dbCount = count(array_filter($names, fn (string $n): bool => $n === 'query_database'));
        $this->assertSame(1, $dbCount);
    }

    public function test_after_skips_non_inference_event(): void
    {
        $middleware = $this->createMiddleware([]);
        $toolCallMessage = new ToolCallMessage(null, [ToolCall::make('test', 'call_1')]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $inferenceEvent = new AIInferenceEvent();
                $toolCallEvent = new ToolCallEvent($toolCallMessage);
        $node = new ToolNode(new InMemoryChatHistory());

        // Should not throw or modify anything
        $middleware->after($node, $toolCallEvent, $state);

        $this->assertCount(0, $state->request->tools);
    }

    public function test_after_does_nothing_when_no_tool_search_in_results(): void
    {
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $event = new AIInferenceEvent();
        $toolResultMessage = new ToolResultMessage([
            ToolCall::make('read_file', 'call_1', [])->setResult('file contents'),
        ]);
        $state->request->messages = [$toolResultMessage];

        $middleware = $this->createMiddleware([]);
        $middleware->after(new ToolNode(new InMemoryChatHistory()), $event, $state);

        $this->assertCount(0, $state->request->tools);
    }

    public function test_after_does_nothing_when_search_found_nothing(): void
    {
        $middleware = $this->createMiddleware([]);
        $node = new ToolNode(new InMemoryChatHistory());

        $toolResultMessage = new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => 'nonexistent'])->setResult('found'),
        ]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $event = new AIInferenceEvent();
        $state->request->messages = [$toolResultMessage];

        $middleware->after($node, $event, $state);

        $this->assertCount(0, $state->request->tools);
    }

    public function test_after_injects_multiple_discovered_tools(): void
    {
        $tool1 = $this->createTool('get_weather', 'Get current weather');
        $tool2 = $this->createTool('get_forecast', 'Get weather forecast');
        $middleware = $this->createMiddleware([$tool1, $tool2]);
        $node = new ToolNode(new InMemoryChatHistory());

        $toolResultMessage = new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => 'weather'])->setResult('found'),
        ]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $event = new AIInferenceEvent();
        $state->request->messages = [$toolResultMessage];

        $middleware->after($node, $event, $state);

        $this->assertCount(2, $state->request->tools);
        $names = array_map(fn (ToolInterface $t): string => $t->getName(), $state->request->tools);
        $this->assertContains('get_weather', $names);
        $this->assertContains('get_forecast', $names);
    }

    // --- ToolSearchTool search behavior ---

    public function test_search_finds_by_name(): void
    {
        $tool = $this->createTool('query_database', 'Execute SQL');
        $searchTool = new ToolSearchTool([$tool]);

        $result = $searchTool->__invoke('database');

        $this->assertStringContainsString('query_database', $result);
        $this->assertCount(1, $searchTool->search('database'));
    }

    public function test_search_finds_by_description(): void
    {
        $tool = $this->createTool('db', 'Execute SQL queries on the database');
        $searchTool = new ToolSearchTool([$tool]);

        $result = $searchTool->__invoke('SQL');

        $this->assertStringContainsString('db', $result);
        $this->assertCount(1, $searchTool->search('SQL'));
    }

    public function test_search_is_case_insensitive(): void
    {
        $tool = $this->createTool('SendEmail', 'Send an email');
        $searchTool = new ToolSearchTool([$tool]);

        $searchTool->__invoke('sendemail');
        $this->assertCount(1, $searchTool->search('sendemail'));
    }

    public function test_search_returns_no_matches(): void
    {
        $tool = $this->createTool('read_file', 'Read file');
        $searchTool = new ToolSearchTool([$tool]);

        $result = $searchTool->__invoke('database');

        $this->assertStringContainsString('No tools found', $result);
        $this->assertCount(0, $searchTool->search('database'));
    }

    // --- MCP tools integration ---

    public function test_middleware_works_with_mcp_generated_tools(): void
    {
        $transport = new FakeMcpTransport(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => []],
            [
                'jsonrpc' => '2.0',
                'id' => 2,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'query_users',
                            'description' => 'Query users from the database',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'filter' => ['type' => 'string'],
                                ],
                                'required' => ['filter'],
                            ],
                        ],
                        [
                            'name' => 'send_notification',
                            'description' => 'Send a notification to users',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'message' => ['type' => 'string'],
                                ],
                                'required' => ['message'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $connector = new McpConnector(['transport' => $transport]);
        $mcpTools = $connector->tools();

        // Verify MCP generated real Tool instances
        $this->assertCount(2, $mcpTools);

        // Use MCP tools as the search pool
        $middleware = new ToolSearchMiddleware($mcpTools);
        $node = new ToolNode(new InMemoryChatHistory());

        // Simulate: model called tool_search for "database" tools.
        // Only query_users should match (description contains "database")
        $discovered = (new ToolSearchTool($mcpTools))->search('database');
        $this->assertCount(1, $discovered);
        $this->assertSame('query_users', $discovered[0]->getName());

        // Middleware injects the MCP tool into the event
        $toolResultMessage = new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => 'database'])->setResult('found'),
        ]);
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), []);
        $event = new AIInferenceEvent();
        $state->request->messages = [$toolResultMessage];

        $middleware->after($node, $event, $state);

        $this->assertCount(1, $state->request->tools);
        $this->assertSame('query_users', $state->request->tools[0]->getName());
    }
}
