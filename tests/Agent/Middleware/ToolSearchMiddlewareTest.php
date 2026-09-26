<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Middleware;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\MCP\McpConnector;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMcpTransport;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tools\FrontendTool;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function array_map;

class ToolSearchMiddlewareTest extends TestCase
{
    protected function createTool(string $name, string $description): Tool
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

    protected function createMiddleware(array $toolPool): ToolSearchMiddleware
    {
        return new ToolSearchMiddleware($toolPool);
    }

    /**
     * @param ToolInterface[] $registered
     */
    protected function resources(array $registered = [], ?ChatHistory $history = null): AgentResources
    {
        return AgentResourcesFactory::make($registered, $history);
    }

    protected function state(Message ...$inbound): AgentState
    {
        $state = new AgentState();
        $state->request = new InferenceRequest(new SystemMessage('instructions'), $inbound);

        return $state;
    }

    /**
     * @return string[]
     */
    protected function registered(AgentResources $resources): array
    {
        return array_map(fn (ToolInterface $tool): string => $tool->getName(), $resources->tools->all());
    }

    protected function searchResult(string $query): ToolResultMessage
    {
        return new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => $query])->setResult('found'),
        ]);
    }

    public function test_it_registers_the_search_tool(): void
    {
        $resources = $this->resources();

        $this->createMiddleware([])->before(new ToolNode(), new AIInferenceEvent(), $this->state(), $resources);

        $this->assertSame(['tool_search'], $this->registered($resources));
        $this->assertInstanceOf(ToolSearchTool::class, $resources->tools->find('tool_search'));
    }

    public function test_it_adds_its_instructions_before_inference(): void
    {
        $state = $this->state();

        $this->createMiddleware([])->before(new ToolNode(), new AIInferenceEvent(), $state, $this->resources());

        $this->assertStringContainsString('tool_search', $state->request->instructions->getContent());
    }

    public function test_it_leaves_the_instructions_alone_before_tool_execution(): void
    {
        $state = $this->state();
        $event = new ToolCallEvent(new ToolCallMessage(null, [ToolCall::make('test', 'call_1')]));

        $this->createMiddleware([])->before(new ToolNode(), $event, $state, $this->resources());

        $this->assertSame('instructions', $state->request->instructions->getContent());
    }

    public function test_it_registers_the_search_tool_once(): void
    {
        $resources = $this->resources([new ToolSearchTool([])]);

        $this->createMiddleware([])->before(new ToolNode(), new AIInferenceEvent(), $this->state(), $resources);

        $this->assertSame(['tool_search'], $this->registered($resources));
    }

    public function test_it_keeps_the_registered_tools(): void
    {
        $resources = $this->resources([$this->createTool('existing', 'An existing tool')]);

        $this->createMiddleware([])->before(new ToolNode(), new AIInferenceEvent(), $this->state(), $resources);

        $this->assertSame(['existing', 'tool_search'], $this->registered($resources));
    }

    public function test_it_registers_the_tools_found_in_the_current_turn(): void
    {
        $middleware = $this->createMiddleware([$this->createTool('query_database', 'Execute SQL queries')]);
        $resources = $this->resources();

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('database')), $resources);

        $this->assertSame(['tool_search', 'query_database'], $this->registered($resources));
    }

    public function test_it_finds_the_turn_in_the_conversation_after_a_pause(): void
    {
        $middleware = $this->createMiddleware([$this->createTool('query_database', 'Execute SQL queries')]);
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Count the users'));
        $history->addMessage(new ToolCallMessage(null, [ToolCall::make('tool_search', 'call_1', ['query' => 'database'])]));
        $history->addMessage($this->searchResult('database'));
        $resources = $this->resources([], $history);

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state(), $resources);

        $this->assertContains('query_database', $this->registered($resources));
    }

    public function test_the_next_turn_starts_without_the_found_tools(): void
    {
        $middleware = $this->createMiddleware([$this->createTool('query_database', 'Execute SQL queries')]);
        $history = new ChatHistory(new InMemoryMessageStore(), 'thread');
        $history->addMessage(new UserMessage('Count the users'));
        $history->addMessage(new ToolCallMessage(null, [ToolCall::make('tool_search', 'call_1', ['query' => 'database'])]));
        $history->addMessage($this->searchResult('database'));
        $history->addMessage(new AssistantMessage('There are 42 users.'));
        $resources = $this->resources([], $history);

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state(new UserMessage('Thanks')), $resources);

        $this->assertSame(['tool_search'], $this->registered($resources));
    }

    public function test_it_registers_a_found_tool_once(): void
    {
        $middleware = $this->createMiddleware([$this->createTool('query_database', 'Execute SQL queries')]);
        $resources = $this->resources([$this->createTool('query_database', 'Execute SQL queries')]);

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('database')), $resources);

        $this->assertSame(['query_database', 'tool_search'], $this->registered($resources));
    }

    public function test_other_tool_results_register_nothing(): void
    {
        // Only a tool_search call is a search: another tool's query argument discovers nothing.
        $middleware = $this->createMiddleware([$this->createTool('query_database', 'Execute SQL queries')]);
        $resources = $this->resources();
        $state = $this->state(new ToolResultMessage([ToolCall::make('web_search', 'call_1', ['query' => 'database'])->setResult('results')]));

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $state, $resources);

        $this->assertSame(['tool_search'], $this->registered($resources));
    }

    public function test_a_search_that_found_nothing_registers_nothing(): void
    {
        $resources = $this->resources();

        $this->createMiddleware([])->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('nonexistent')), $resources);

        $this->assertSame(['tool_search'], $this->registered($resources));
    }

    public function test_it_registers_every_tool_a_search_found(): void
    {
        $middleware = $this->createMiddleware([
            $this->createTool('get_weather', 'Get current weather'),
            $this->createTool('get_forecast', 'Get weather forecast'),
        ]);
        $resources = $this->resources();

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('weather')), $resources);

        $this->assertSame(['tool_search', 'get_weather', 'get_forecast'], $this->registered($resources));
    }

    /**
     * @param ToolInterface[] $tools
     */
    protected function agent(FakeAIProvider $provider, InMemoryPersistence $persistence, InMemoryMessageStore $store, array $tools, array $pool): Agent
    {
        $agent = Agent::make(workflowId: 'thread')->setPersistence($persistence)->setMessageStore($store)
            ->addGlobalMiddleware(new ToolSearchMiddleware($pool));
        $agent->setAiProvider($provider)->setTools($tools);

        return $agent;
    }

    public function test_found_tools_are_offered_again_after_a_deferred_tool_pause(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $pool = [$this->createTool('query_database', 'Execute SQL queries on the database')];
        $client = new FrontendTool('client_action', 'Runs in the browser');

        $paused = $this->agent(new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'call_1', ['query' => 'database'])]),
            new ToolCallMessage(null, [new ToolCall('client_action', 'call_2', [], deferred: true)]),
        ), $persistence, $store, [$client], $pool)->chat(new UserMessage('Count the users'));
        $this->assertTrue($paused->isInterrupted());

        $provider = new FakeAIProvider(new AssistantMessage('There are 42 users.'));
        $this->agent($provider, $persistence, $store, [$client], $pool)
            ->submitToolResults(['call_2' => ['result' => 'ok']])->run();

        $offered = array_map(fn (ToolInterface $tool): string => $tool->getName(), $provider->getRecorded()[0]->tools);
        $this->assertSame(['client_action', 'tool_search', 'query_database'], $offered);
    }

    public function test_an_approved_call_to_a_found_tool_runs_after_the_pause(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $pool = [$this->createTool('query_database', 'Execute SQL queries on the database')->requireApproval()];

        $paused = $this->agent(new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('tool_search', 'call_1', ['query' => 'database'])]),
            new ToolCallMessage(null, [ToolCall::make('query_database', 'call_2')]),
        ), $persistence, $store, [], $pool)->chat(new UserMessage('Count the users'));
        $this->assertTrue($paused->isInterrupted());

        $agent = $this->agent(new FakeAIProvider(new AssistantMessage('There are 42 users.')), $persistence, $store, [], $pool);
        $completed = $agent->submitApprovalDecisions(['call_2' => 'approve'])->run();

        $this->assertSame('There are 42 users.', $completed->getMessage()->getContent());
        $results = $agent->getChatHistory()->getMessages()[4];
        $this->assertInstanceOf(ToolResultMessage::class, $results);
        $this->assertSame('file contents', $results->getToolCalls()[0]->getResult());
    }

    public function test_a_found_tool_cannot_shadow_a_registered_tool(): void
    {
        $own = $this->createTool('query_database', 'Execute SQL queries on the primary');
        $impostor = $this->createTool('query_database', 'Execute SQL queries on the replica');
        $resources = $this->resources([$own]);

        $this->createMiddleware([$impostor])
            ->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('database')), $resources);

        $this->assertSame(['query_database', 'tool_search'], $this->registered($resources));
        $this->assertSame($own, $resources->tools->find('query_database'));
    }

    public function test_a_search_call_with_a_malformed_query_registers_nothing(): void
    {
        $middleware = $this->createMiddleware([$this->createTool('query_database', 'Execute SQL queries')]);
        $forged = new ToolResultMessage([
            ToolCall::make('tool_search', 'call_1', ['query' => ['database']])->setResult('found'),
            ToolCall::make('tool_search', 'call_2', [])->setResult('found'),
        ]);
        $resources = $this->resources();

        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state($forged), $resources);

        $this->assertSame(['tool_search'], $this->registered($resources));
    }

    public function test_the_limit_bounds_the_tools_a_search_registers(): void
    {
        $pool = [];
        foreach (['a', 'b', 'c', 'd'] as $suffix) {
            $pool[] = $this->createTool("report_{$suffix}", 'Build a report');
        }
        $resources = $this->resources();

        (new ToolSearchMiddleware($pool, topN: 2))
            ->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('report')), $resources);

        $this->assertSame(['tool_search', 'report_a', 'report_b'], $this->registered($resources));
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

        // Simulate: model called tool_search for "database" tools.
        // Only query_users should match (description contains "database")
        $discovered = (new ToolSearchTool($mcpTools))->search('database');
        $this->assertCount(1, $discovered);
        $this->assertSame('query_users', $discovered[0]->getName());

        // The middleware registers the MCP tool the search found
        $resources = $this->resources();
        $middleware->before(new ToolNode(), new AIInferenceEvent(), $this->state($this->searchResult('database')), $resources);

        $this->assertSame(['tool_search', 'query_users'], $this->registered($resources));
    }
}
