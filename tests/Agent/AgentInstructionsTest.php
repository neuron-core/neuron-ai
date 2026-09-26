<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\Agent\Stub\QueryDatabaseTool;
use NeuronAI\Tests\Agent\Stub\WeatherToolkit;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Middleware\ToolSearchMiddleware;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;
use PHPUnit\Framework\TestCase;

use function array_map;
use function substr_count;

class AgentInstructionsTest extends TestCase
{
    public function test_default_instructions_are_not_cached(): void
    {
        $blocks = ($agent = Agent::make())->getInstructions()->getTextBlocks();

        $this->assertCount(1, $blocks);
        $this->assertInstanceOf(SystemContent::class, $blocks[0]);
        $this->assertFalse($blocks[0]->isCached());
    }

    // ---------------------------------------------------------------
    // Unit: middleware appends its prompt as a new content block
    // ---------------------------------------------------------------

    public function test_tool_search_middleware_appends_instructions_block(): void
    {
        $middleware = new ToolSearchMiddleware([]);
        $state = new AgentState();
        $state->request = new InferenceRequest(
            new SystemMessage([new SystemContent('Block one'), new SystemContent('Block two')]),
            []
        );
        $event = new AIInferenceEvent();

        $middleware->before(new ToolNode(), $event, $state, AgentResourcesFactory::make());

        $blocks = $state->request->instructions->getTextBlocks();
        $this->assertCount(3, $blocks);
        $this->assertSame('Block one', $blocks[0]->content);
        $this->assertSame('Block two', $blocks[1]->content);
        $this->assertStringContainsString('tool_search', $blocks[2]->content);
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
    // Integration: toolkit guidelines reach the provider system prompt
    // ---------------------------------------------------------------

    public function test_toolkit_guidelines_reach_the_provider_system_prompt(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done.'));

        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $agent->setInstructions('You are a helpful assistant.');
        $agent->addTool(new WeatherToolkit());

        $agent->chat(new UserMessage('What is the weather in Rome?'));

        $record = $provider->getRecorded()[0];

        $systemPrompt = $record->systemPrompt->getContent();
        $this->assertStringContainsString('You are a helpful assistant.', $systemPrompt);
        $this->assertStringContainsString('<TOOLS-GUIDELINES>', $systemPrompt);
        $this->assertStringContainsString('# WeatherToolkit', $systemPrompt);
        $this->assertStringContainsString('Always report temperatures in Celsius.', $systemPrompt);
        $this->assertStringContainsString('get_weather', $systemPrompt);

        // The toolkit's tools are expanded into the provider call.
        $toolNames = array_map(
            static fn (\NeuronAI\Tools\ToolInterface $t): string => $t->getName(),
            $record->tools
        );
        $this->assertContains('get_weather', $toolNames);
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

    public function test_toolkit_guidelines_never_accumulate_across_turns(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('First'), new AssistantMessage('Second'));
        $agent = Agent::make()->setAiProvider($provider)->setInstructions('You are a helpful assistant.');
        $agent->addTool(new WeatherToolkit());

        $agent->chat(new UserMessage('Weather in Rome?'));
        $agent->chat(new UserMessage('And in Paris?'));

        $this->assertCount(2, $provider->getRecorded());
        foreach ($provider->getRecorded() as $record) {
            $prompt = (string) $record->systemPrompt?->getContent();
            $this->assertStringStartsWith("You are a helpful assistant.\n\n<TOOLS-GUIDELINES>\n# WeatherToolkit\n", $prompt);
            $this->assertSame(1, substr_count($prompt, '<TOOLS-GUIDELINES>'));
            $this->assertSame(1, substr_count($prompt, 'Always report temperatures in Celsius.'));
        }
        $this->assertSame('You are a helpful assistant.', $agent->getInstructions()->getContent(), 'The configured instructions stay untouched');
    }

    public function test_a_toolkit_without_guidelines_adds_no_guidelines_block(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done'));
        $agent = Agent::make()->setAiProvider($provider)->setInstructions('You are a helpful assistant.');
        $agent->addTool(new class () extends AbstractToolkit {
            public function provide(): array
            {
                return [new GetWeatherTool()];
            }
        });

        $agent->chat(new UserMessage('Weather in Rome?'));

        $record = $provider->getRecorded()[0];
        $this->assertSame('You are a helpful assistant.', $record->systemPrompt?->getContent());
        $this->assertSame(['get_weather'], array_map(static fn (ToolInterface|ProviderToolInterface $tool): string => $tool->getName(), $record->tools));
    }

    public function test_a_plain_string_from_the_instructions_hook_becomes_a_system_message(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done'));
        $agent = new class () extends Agent {
            protected function instructions(): string
            {
                return 'Hook instructions';
            }
        };
        $agent->setAiProvider($provider);

        $agent->chat(new UserMessage('Hi'));

        $this->assertInstanceOf(SystemMessage::class, $agent->getInstructions());
        $this->assertSame('Hook instructions', $provider->getRecorded()[0]->systemPrompt?->getContent());
    }

    public function test_explicit_instructions_win_over_the_hook(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Done'));
        $agent = new class () extends Agent {
            protected function instructions(): string
            {
                return 'Hook instructions';
            }
        };
        $agent->setAiProvider($provider)->setInstructions(new SystemMessage('Explicit instructions'));

        $agent->chat(new UserMessage('Hi'));

        $this->assertSame('Explicit instructions', $provider->getRecorded()[0]->systemPrompt?->getContent());
    }
}
