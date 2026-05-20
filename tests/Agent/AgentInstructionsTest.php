<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Middleware\ToolSearchTool;
use NeuronAI\Agent\Middleware\TodoPlanning;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
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
    // Unit: middleware does NOT touch instructions
    // ---------------------------------------------------------------

    public function test_tool_search_middleware_preserves_string_instructions(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $event = new AIInferenceEvent('Original instructions', []);

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertSame('Original instructions', $event->instructions);
    }

    public function test_tool_search_middleware_preserves_array_instructions(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $event = new AIInferenceEvent(
            [new SystemContent('Block one'), new SystemContent('Block two')],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertCount(2, $event->instructions);
        $this->assertSame('Block one', $event->instructions[0]->content);
        $this->assertSame('Block two', $event->instructions[1]->content);
    }

    public function test_todo_planning_middleware_injects_into_string_instructions(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent('Original instructions', []);

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsString($event->instructions);
        $this->assertStringContainsString('Original instructions', $event->instructions);
        $this->assertStringContainsString('write_todos', $event->instructions);
    }

    public function test_todo_planning_middleware_injects_into_array_instructions(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent(
            [new SystemContent('Block one')],
            []
        );

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertIsArray($event->instructions);
        $this->assertCount(2, $event->instructions);
        $this->assertSame('Block one', $event->instructions[0]->content);
        $this->assertStringContainsString('write_todos', $event->instructions[1]->content);
    }

    public function test_todo_planning_does_not_duplicate_string_instructions_on_multiple_passes(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent('Original instructions', []);

        // Simulate multiple ChatNode passes (tool loop)
        $middleware->before(new ToolNode(), $event, new AgentState());
        $firstInstructions = $event->instructions;

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertSame($firstInstructions, $event->instructions, 'Instructions should not grow on second pass.');
    }

    public function test_todo_planning_does_not_duplicate_array_instructions_on_multiple_passes(): void
    {
        $middleware = new TodoPlanning();
        $event = new AIInferenceEvent(
            [new SystemContent('Block one')],
            []
        );

        // Simulate multiple ChatNode passes (tool loop)
        $middleware->before(new ToolNode(), $event, new AgentState());
        $this->assertCount(2, $event->instructions);

        $middleware->before(new ToolNode(), $event, new AgentState());

        $this->assertCount(2, $event->instructions, 'Instructions should not grow on second pass.');
    }

    // ---------------------------------------------------------------
    // Integration: agent with string instructions + tool search
    // ---------------------------------------------------------------

    public function test_agent_tool_search_with_string_instructions(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool = new ToolSearchTool($toolPool);
        $searchTool->setCallId('call_1');
        $searchTool->setInputs(['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                (clone $dbTool)->setCallId('call_2')->setInputs(['sql' => 'SELECT 1']),
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

        // String instructions passed to provider untouched
        $records = $provider->getRecorded();
        $this->assertIsString($records[0]->systemPrompt);
        $this->assertStringContainsString('You are a helpful assistant.', $records[0]->systemPrompt);
    }

    public function test_agent_tool_search_with_array_instructions(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool = new ToolSearchTool($toolPool);
        $searchTool->setCallId('call_1');
        $searchTool->setInputs(['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                (clone $dbTool)->setCallId('call_2')->setInputs(['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions([
            new SystemContent('You are a helpful assistant.'),
        ]);
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $message = $agent->chat(new UserMessage('Query the database'))->getMessage();

        $this->assertSame('Done.', $message->getContent());
        $provider->assertCallCount(3);

        // Array instructions passed to provider untouched
        $records = $provider->getRecorded();
        $this->assertIsArray($records[0]->systemPrompt);
        $firstBlock = $records[0]->systemPrompt[0] ?? null;
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

        $searchTool = new ToolSearchTool($toolPool);
        $searchTool->setCallId('call_1');
        $searchTool->setInputs(['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                (clone $dbTool)->setCallId('call_2')->setInputs(['sql' => 'SELECT 1']),
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

        $searchTool1 = new ToolSearchTool($toolPool);
        $searchTool1->setCallId('call_1');
        $searchTool1->setInputs(['query' => 'database']);

        $searchTool2 = new ToolSearchTool($toolPool);
        $searchTool2->setCallId('call_2');
        $searchTool2->setInputs(['query' => 'database']);

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

        $searchTool = new ToolSearchTool($toolPool);
        $searchTool->setCallId('call_1');
        $searchTool->setInputs(['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [
                $searchTool,
                (clone $weatherTool)->setCallId('call_2')->setInputs(['location' => 'Rome']),
            ]),
            new ToolCallMessage(null, [
                (clone $dbTool)->setCallId('call_3')->setInputs(['sql' => 'SELECT 1']),
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
    // Integration: array instructions preserved through tool loop
    // ---------------------------------------------------------------

    public function test_array_instructions_preserved_untouched_through_tool_loop(): void
    {
        $dbTool = new QueryDatabaseTool();
        $toolPool = [clone $dbTool];

        $searchTool = new ToolSearchTool($toolPool);
        $searchTool->setCallId('call_1');
        $searchTool->setInputs(['query' => 'database']);

        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$searchTool]),
            new ToolCallMessage(null, [
                (clone $dbTool)->setCallId('call_2')->setInputs(['sql' => 'SELECT 1']),
            ]),
            new AssistantMessage('Done.'),
        );

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions([
            new SystemContent('Base instructions'),
            (new SystemContent('Cached instructions'))->cache(),
        ]);
        $agent->addGlobalMiddleware(new ToolSearchMiddleware($toolPool));

        $agent->chat(new UserMessage('Query the database'))->getMessage();

        $records = $provider->getRecorded();

        // Every provider call should receive the original array instructions untouched
        foreach ($records as $record) {
            $this->assertIsArray($record->systemPrompt, 'Instructions should remain array throughout the tool loop.');
            $this->assertCount(2, $record->systemPrompt);
            $this->assertSame('Base instructions', $record->systemPrompt[0]->content);
            $this->assertSame('Cached instructions', $record->systemPrompt[1]->content);
            $this->assertTrue($record->systemPrompt[1]->isCached());
        }
    }
}
