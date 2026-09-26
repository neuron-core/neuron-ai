<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\TodoPlanning;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\TodoPlanning\TodoPlanningToolkit;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

use function array_map;
use function substr_count;

class TodoPlanningToolkitTest extends TestCase
{
    /** @var array<int, array{content: string, status: string}> */
    protected array $todos = [['content' => 'Design the schema', 'status' => 'in_progress']];

    public function test_guidelines_reach_every_inference_once(): void
    {
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('write_todos', 'call_1', ['todos' => $this->todos])]),
            new AssistantMessage('Done'),
        );

        Agent::make(workflowId: 'thread')->setAiProvider($provider)->addTool(TodoPlanningToolkit::make())
            ->chat(new UserMessage('Build the blog'));

        $provider->assertCallCount(2);
        foreach ($provider->getRecorded() as $record) {
            self::assertSame(1, substr_count((string) $record->systemPrompt?->getContent(), 'manage and plan complex objectives'));
            self::assertSame(['write_todos'], array_map(fn (ToolInterface $tool): string => $tool->getName(), $record->tools));
        }
    }

    public function test_the_todo_list_is_the_write_todos_call_in_the_conversation(): void
    {
        $agent = Agent::make(workflowId: 'thread')->addTool(TodoPlanningToolkit::make())->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('write_todos', 'call_1', ['todos' => $this->todos])]),
            new AssistantMessage('Done'),
        ));

        $agent->chat(new UserMessage('Build the blog'));

        [, $call, $result] = $agent->getChatHistory()->getMessages();
        self::assertInstanceOf(ToolCallMessage::class, $call);
        self::assertSame($this->todos, $call->getToolCalls()[0]->getInput('todos'));
        self::assertInstanceOf(ToolResultMessage::class, $result);
        self::assertSame('Updated to do list to: [{"content":"Design the schema","status":"in_progress"}]', $result->getToolCalls()[0]->getResult());
    }

    public function test_write_todos_runs_after_an_approval_pause(): void
    {
        $persistence = new InMemoryPersistence();
        $store = new InMemoryMessageStore();
        $make = function (FakeAIProvider $provider) use ($persistence, $store): Agent {
            $agent = Agent::make(workflowId: 'thread')->setPersistence($persistence)->setMessageStore($store);
            $agent->setAiProvider($provider)->setTools([(new SearchTool())->requireApproval(), TodoPlanningToolkit::make()]);

            return $agent;
        };

        $paused = $make(new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('write_todos', 'call_1', ['todos' => $this->todos]),
            ToolCall::make('search', 'call_2', ['query' => 'php']),
        ])))->chat(new UserMessage('Build the blog'));
        self::assertTrue($paused->isInterrupted());

        $agent = $make(new FakeAIProvider(new AssistantMessage('Done')));
        $completed = $agent->submitApprovalDecisions(['call_2' => 'approve'])->run();

        self::assertSame('Done', $completed->getMessage()->getContent());
        $results = $agent->getChatHistory()->getMessages()[2];
        self::assertInstanceOf(ToolResultMessage::class, $results);
        self::assertSame('Updated to do list to: [{"content":"Design the schema","status":"in_progress"}]', $results->getToolCalls()[0]->getResult());
    }

    public function test_parallel_tool_calls_keep_the_todo_list_in_the_conversation(): void
    {
        $agent = Agent::make(workflowId: 'thread')->parallelToolCalls()
            ->setTools([new SearchTool(), TodoPlanningToolkit::make()])
            ->setAiProvider(new FakeAIProvider(
                new ToolCallMessage(null, [
                    ToolCall::make('write_todos', 'call_1', ['todos' => $this->todos]),
                    ToolCall::make('search', 'call_2', ['query' => 'php']),
                ]),
                new AssistantMessage('Done'),
            ));

        $agent->chat(new UserMessage('Build the blog'));

        [, $call, $results] = $agent->getChatHistory()->getMessages();
        self::assertSame($this->todos, $call->getToolCalls()[0]->getInput('todos'));
        self::assertInstanceOf(ToolResultMessage::class, $results);
        self::assertSame('Updated to do list to: [{"content":"Design the schema","status":"in_progress"}]', $results->getToolCalls()[0]->getResult());
        self::assertSame('Results for: php', $results->getToolCalls()[1]->getResult());
    }
}
