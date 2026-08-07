<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Tools\ToolCall;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use PHPUnit\Framework\TestCase;

use function array_map;

class QueryDatabaseTool extends Tool
{
    protected string $name = 'query_database';

    protected ?string $description = 'Execute SQL queries on the database';

    protected function properties(): array
    {
        return [
            new ToolProperty('sql', PropertyType::STRING, 'SQL query', true),
        ];
    }

    public function __invoke(string $sql): string
    {
        return "Results for: {$sql}";
    }
}

class GetWeatherTool extends Tool
{
    protected string $name = 'get_weather';

    protected ?string $description = 'Get current weather for a location';

    protected function properties(): array
    {
        return [
            new ToolProperty('location', PropertyType::STRING, 'Location', true),
        ];
    }

    public function __invoke(string $location): string
    {
        return "Weather for {$location}: sunny";
    }
}

class AgentInstructionsTest extends TestCase
{
    // ---------------------------------------------------------------
    // Unit: middleware appends its prompt as a new content block
    // ---------------------------------------------------------------

    public function test_tool_search_middleware_appends_instructions_block(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $event = new AIInferenceEvent(
            new SystemMessage([new SystemContent('Block one'), new SystemContent('Block two')]),
            []
        );

        $middleware->before(new ToolNode(new InMemoryChatHistory()), $event, new AgentState());

        $blocks = $event->instructions->getTextBlocks();
        $this->assertCount(3, $blocks);
        $this->assertSame('Block one', $blocks[0]->content);
        $this->assertSame('Block two', $blocks[1]->content);
        $this->assertStringContainsString('tool_search', $blocks[2]->content);
    }

    public function test_todo_planning_middleware_appends_instructions_block(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent(new SystemMessage('Original instructions'), []);

        $middleware->before(new ToolNode(new InMemoryChatHistory()), $event, new AgentState());

        $blocks = $event->instructions->getTextBlocks();
        $this->assertCount(2, $blocks);
        $this->assertSame('Original instructions', $blocks[0]->content);
        $this->assertStringContainsString('write_todos', $blocks[1]->content);
    }

    public function test_todo_planning_does_not_duplicate_instructions_on_multiple_passes(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent(new SystemMessage('Original instructions'), []);

        // Simulate multiple ChatNode passes (tool loop)
        $middleware->before(new ToolNode(new InMemoryChatHistory()), $event, new AgentState());
        $this->assertCount(2, $event->instructions->getContentBlocks());

        $middleware->before(new ToolNode(new InMemoryChatHistory()), $event, new AgentState());

        $this->assertCount(2, $event->instructions->getContentBlocks(), 'Instructions should not grow on second pass.');
    }

    // ---------------------------------------------------------------
    // Integration: agent with string instructions + tool search
    // ---------------------------------------------------------------

    public function test_agent_tool_search_with_string_instructions(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool = ToolCall::make('tool_search', 'call_1', ['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                ToolCall::make($dbTool->getName(), 'call_2', ['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('You are a helpful assistant.');
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $message = $agent->chat(new UserMessage('Query the database'))->getMessage();

        $this->assertSame('Done.', $message->getContent());
        $provider->assertCallCount(3);

        // String instructions reach the provider as a SystemMessage
        $records = $provider->getRecorded();
        $this->assertInstanceOf(SystemMessage::class, $records[0]->systemPrompt);
        $this->assertStringContainsString('You are a helpful assistant.', $records[0]->systemPrompt->getContent());
    }

    public function test_agent_tool_search_with_block_instructions(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool = ToolCall::make('tool_search', 'call_1', ['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                ToolCall::make($dbTool->getName(), 'call_2', ['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions(new SystemMessage(
            new SystemContent('You are a helpful assistant.'),
        ));
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $message = $agent->chat(new UserMessage('Query the database'))->getMessage();

        $this->assertSame('Done.', $message->getContent());
        $provider->assertCallCount(3);

        // The original block reaches the provider untouched
        $records = $provider->getRecorded();
        $firstBlock = $records[0]->systemPrompt->getContentBlocks()[0] ?? null;
        $this->assertInstanceOf(SystemContent::class, $firstBlock);
        $this->assertSame('You are a helpful assistant.', $firstBlock->content);
    }

    // ---------------------------------------------------------------
    // Integration: tool search discovers multiple tools
    // ---------------------------------------------------------------

    public function test_agent_tool_search_discovers_multiple_tools(): void
    {
        $dbTool = new QueryDatabaseTool();
        $weatherTool = new GetWeatherTool();
        $toolPool = [clone $dbTool, clone $weatherTool];

        $searchTool = ToolCall::make('tool_search', 'call_1', ['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                ToolCall::make($dbTool->getName(), 'call_2', ['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('You are a helpful assistant.');
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $agent->chat(new UserMessage('Query the database'))->getMessage();

        $records = $provider->getRecorded();
        $this->assertCount(3, $records);

        // Second call should have both tool_search and the discovered query_database
        $secondCallTools = array_map(
            static fn (\NeuronAI\Tools\ToolInterface $t): string => $t->getName(),
            $records[1]->tools
        );
        $this->assertContains('tool_search', $secondCallTools);
        $this->assertContains('query_database', $secondCallTools);
    }

    // ---------------------------------------------------------------
    // Integration: tool_search deduplication
    // ---------------------------------------------------------------

    public function test_agent_tool_search_does_not_duplicate_discovered_tool(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool1 = ToolCall::make('tool_search', 'call_1', ['query' => 'database']);

        $searchTool2 = ToolCall::make('tool_search', 'call_2', ['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool1]),
            new ToolCallMessage(null, [$searchTool2]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('You are a helpful assistant.');
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $agent->chat(new UserMessage('Query the database'))->getMessage();

        // After both searches, query_database should appear exactly once in tools
        $records = $provider->getRecorded();
        $dbCount = 0;
        foreach ($records[2]->tools as $tool) {
            if ($tool->getName() === 'query_database') {
                $dbCount++;
            }
        }
        $this->assertSame(1, $dbCount, 'Discovered tool should not be duplicated.');
    }

    // ---------------------------------------------------------------
    // Integration: tool_search + regular tool called together
    // ---------------------------------------------------------------

    public function test_agent_tool_search_and_regular_tool_in_same_call(): void
    {
        $dbTool = new QueryDatabaseTool();
        $weatherTool = new GetWeatherTool();
        $toolPool = [clone $dbTool];

        $searchTool = ToolCall::make('tool_search', 'call_1', ['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                $searchTool,
                ToolCall::make($weatherTool->getName(), 'call_2', ['location' => 'Rome']),
            ]),
            new ToolCallMessage(null, [
                ToolCall::make($dbTool->getName(), 'call_3', ['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('You are a helpful assistant.');
        $agent->addTool($weatherTool);
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $message = $agent->chat(new UserMessage('What is the weather and query the database?'))->getMessage();

        $this->assertSame('Done.', $message->getContent());
        $provider->assertCallCount(3);
    }

    // ---------------------------------------------------------------
    // Integration: instruction blocks preserved through tool loop
    // ---------------------------------------------------------------

    public function test_instructions_change_one_time_through_tool_loop(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool = ToolCall::make('tool_search', 'call_1', ['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                ToolCall::make($dbTool->getName(), 'call_2', ['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions(new SystemMessage([
            new SystemContent('Base instructions'),
            (new SystemContent('Cached instructions'))->cache(),
        ]));
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $agent->chat(new UserMessage('Query the database'))->getMessage();

        $records = $provider->getRecorded();

        // Every provider call should receive the original blocks untouched,
        // with the tool_search prompt injected exactly once.
        foreach ($records as $record) {
            $blocks = $record->systemPrompt->getTextBlocks();
            $this->assertCount(3, $blocks);
            $this->assertSame('Base instructions', $blocks[0]->content);
            $this->assertInstanceOf(SystemContent::class, $blocks[1]);
            $this->assertSame('Cached instructions', $blocks[1]->content);
            $this->assertTrue($blocks[1]->isCached());
        }
    }
}
