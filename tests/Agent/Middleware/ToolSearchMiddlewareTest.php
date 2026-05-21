<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tools\Tool;
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

    private function createMiddleware(array $toolPool, ?string $prompt = null): ToolSearchMiddleware
    {
        return new ToolSearchMiddleware($toolPool, $prompt);
    }

    // --- before() tests ---

    public function test_before_injects_tool_search_into_inference_event(): void
    {
        $middleware = $this->createMiddleware([]);
        $event = new AIInferenceEvent('instructions', []);
        $node = new ToolNode();

        $middleware->before($node, $event, new AgentState());

        $this->assertCount(1, $event->tools);
        $this->assertInstanceOf(ToolSearchTool::class, $event->tools[0]);
    }

    public function test_before_does_modify_instructions(): void
    {
        $middleware = $this->createMiddleware([]);
        $event = new AIInferenceEvent('original instructions', []);
        $node = new ToolNode();

        $middleware->before($node, $event, new AgentState());

        $this->assertNotSame('original instructions', $event->instructions);
    }

    public function test_before_skips_non_inference_event(): void
    {
        $middleware = $this->createMiddleware([]);
        $tool = $this->createTool('test', 'test');
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent('instructions', []);
        $toolCallEvent = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $node = new ToolNode();

        $originalInstructions = $inferenceEvent->instructions;
        $middleware->before($node, $toolCallEvent, new AgentState());

        // Instructions should not have been modified
        $this->assertSame($originalInstructions, $inferenceEvent->instructions);
    }

    public function test_before_does_not_duplicate_tool_search(): void
    {
        $middleware = $this->createMiddleware([]);
        $existing = new ToolSearchTool([]);
        $event = new AIInferenceEvent('instructions', [$existing]);
        $node = new ToolNode();

        $middleware->before($node, $event, new AgentState());

        $count = 0;
        foreach ($event->tools as $tool) {
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
        $event = new AIInferenceEvent('instructions', [$existingTool]);
        $node = new ToolNode();

        $middleware->before($node, $event, new AgentState());

        $names = array_map(fn (ToolInterface $t): string => $t->getName(), $event->tools);
        $this->assertContains('existing', $names);
        $this->assertContains('tool_search', $names);
    }

    // --- after() tests ---

    public function test_after_injects_discovered_tools_into_event(): void
    {
        $dbTool = $this->createTool('query_database', 'Execute SQL queries');
        $middleware = $this->createMiddleware([$dbTool]);
        $node = new ToolNode();

        $searchTool = new ToolSearchTool([$dbTool]);
        $searchTool->setInputs(['query' => 'database']);
        $searchTool->execute();

        $toolResultMessage = new ToolResultMessage([$searchTool]);
        $event = new AIInferenceEvent('instructions', []);
        $event->setMessages($toolResultMessage);

        $middleware->after($node, $event, new AgentState());

        $this->assertCount(1, $event->tools);
        $this->assertSame('query_database', $event->tools[0]->getName());
    }

    public function test_after_deduplicates_by_tool_name(): void
    {
        $dbTool = $this->createTool('query_database', 'Execute SQL queries');
        $middleware = $this->createMiddleware([$dbTool]);
        $node = new ToolNode();

        $existingDbTool = $this->createTool('query_database', 'Execute SQL queries');
        $event = new AIInferenceEvent('instructions', [$existingDbTool]);

        $searchTool = new ToolSearchTool([$dbTool]);
        $searchTool->setInputs(['query' => 'database']);
        $searchTool->execute();

        $toolResultMessage = new ToolResultMessage([$searchTool]);
        $event->setMessages($toolResultMessage);

        $middleware->after($node, $event, new AgentState());

        $names = array_map(fn (ToolInterface $t): string => $t->getName(), $event->tools);
        $dbCount = count(array_filter($names, fn (string $n): bool => $n === 'query_database'));
        $this->assertSame(1, $dbCount);
    }

    public function test_after_skips_non_inference_event(): void
    {
        $middleware = $this->createMiddleware([]);
        $tool = $this->createTool('test', 'test');
        $toolCallMessage = new ToolCallMessage(null, [$tool]);
        $inferenceEvent = new AIInferenceEvent('instructions', []);
        $toolCallEvent = new ToolCallEvent($toolCallMessage, $inferenceEvent);
        $node = new ToolNode();

        // Should not throw or modify anything
        $middleware->after($node, $toolCallEvent, new AgentState());

        $this->assertCount(0, $inferenceEvent->tools);
    }

    public function test_after_does_nothing_when_no_tool_search_in_results(): void
    {
        $regularTool = $this->createTool('read_file', 'Read file contents');
        $regularTool->setInputs([]);
        $regularTool->execute();

        $event = new AIInferenceEvent('instructions', []);
        $toolResultMessage = new ToolResultMessage([$regularTool]);
        $event->setMessages($toolResultMessage);

        $middleware = $this->createMiddleware([]);
        $middleware->after(new ToolNode(), $event, new AgentState());

        $this->assertCount(0, $event->tools);
    }

    public function test_after_does_nothing_when_search_found_nothing(): void
    {
        $middleware = $this->createMiddleware([]);
        $node = new ToolNode();

        $searchTool = new ToolSearchTool([]);
        $searchTool->setInputs(['query' => 'nonexistent']);
        $searchTool->execute();

        $toolResultMessage = new ToolResultMessage([$searchTool]);
        $event = new AIInferenceEvent('instructions', []);
        $event->setMessages($toolResultMessage);

        $middleware->after($node, $event, new AgentState());

        $this->assertCount(0, $event->tools);
    }

    public function test_after_injects_multiple_discovered_tools(): void
    {
        $tool1 = $this->createTool('get_weather', 'Get current weather');
        $tool2 = $this->createTool('get_forecast', 'Get weather forecast');
        $middleware = $this->createMiddleware([$tool1, $tool2]);
        $node = new ToolNode();

        $searchTool = new ToolSearchTool([$tool1, $tool2]);
        $searchTool->setInputs(['query' => 'weather']);
        $searchTool->execute();

        $toolResultMessage = new ToolResultMessage([$searchTool]);
        $event = new AIInferenceEvent('instructions', []);
        $event->setMessages($toolResultMessage);

        $middleware->after($node, $event, new AgentState());

        $this->assertCount(2, $event->tools);
        $names = array_map(fn (ToolInterface $t): string => $t->getName(), $event->tools);
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
        $this->assertCount(1, $searchTool->discoveredTools());
    }

    public function test_search_finds_by_description(): void
    {
        $tool = $this->createTool('db', 'Execute SQL queries on the database');
        $searchTool = new ToolSearchTool([$tool]);

        $result = $searchTool->__invoke('SQL');

        $this->assertStringContainsString('db', $result);
        $this->assertCount(1, $searchTool->discoveredTools());
    }

    public function test_search_is_case_insensitive(): void
    {
        $tool = $this->createTool('SendEmail', 'Send an email');
        $searchTool = new ToolSearchTool([$tool]);

        $searchTool->__invoke('sendemail');
        $this->assertCount(1, $searchTool->discoveredTools());
    }

    public function test_search_returns_no_matches(): void
    {
        $tool = $this->createTool('read_file', 'Read file');
        $searchTool = new ToolSearchTool([$tool]);

        $result = $searchTool->__invoke('database');

        $this->assertStringContainsString('No tools found', $result);
        $this->assertCount(0, $searchTool->discoveredTools());
    }

    public function test_search_uses_custom_callback(): void
    {
        $tool1 = $this->createTool('read_file', 'Read file');
        $tool2 = $this->createTool('write_file', 'Write file');

        $searchTool = new ToolSearchTool(
            [$tool1, $tool2],
            fn (string $query, ToolInterface $tool): bool => $tool->getName() === $query
        );

        $searchTool->__invoke('write_file');

        $discovered = $searchTool->discoveredTools();
        $this->assertCount(1, $discovered);
        $this->assertSame('write_file', $discovered[0]->getName());
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
        $node = new ToolNode();

        // Simulate: model calls tool_search for "database" tools
        $searchTool = new ToolSearchTool($mcpTools);
        $searchTool->setInputs(['query' => 'database']);
        $searchTool->execute();

        // Only query_users should match (description contains "database")
        $discovered = $searchTool->discoveredTools();
        $this->assertCount(1, $discovered);
        $this->assertSame('query_users', $discovered[0]->getName());

        // Middleware injects the MCP tool into the event
        $toolResultMessage = new ToolResultMessage([$searchTool]);
        $event = new AIInferenceEvent('instructions', []);
        $event->setMessages($toolResultMessage);

        $middleware->after($node, $event, new AgentState());

        $this->assertCount(1, $event->tools);
        $this->assertSame('query_users', $event->tools[0]->getName());
    }
}
