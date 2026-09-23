<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Observability\Events\WorkflowStart;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Middleware\Stub\RecordingAgentMiddleware;
use NeuronAI\Tests\Agent\Stub\AgentFailingTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Agent\Stub\WeatherAgent;
use NeuronAI\Tests\Agent\Stub\WeatherToolkit;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tests\Workflow\Stub\FirstNode;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StartEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function count;
use function iterator_to_array;
use function substr_count;

class AgentConfigurationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        foreach (['chat', 'stream', 'structured'] as $mode) {
            yield $mode => [$mode];
        }
    }

    protected function interact(Agent $agent, string $mode): void
    {
        $message = new UserMessage('Generate a user');
        if ($mode === 'stream') {
            iterator_to_array($agent->stream($message));
        } elseif ($mode === 'structured') {
            $this->assertInstanceOf(User::class, $agent->structured($message, User::class));
        } else {
            $agent->chat($message);
        }
    }

    #[DataProvider('modes')]
    public function test_provider_and_instructions_changes_apply_to_the_next_interaction(string $mode): void
    {
        $first = new FakeAIProvider(new AssistantMessage('{"name":"First"}'));
        $second = new FakeAIProvider(new AssistantMessage('{"name":"Second"}'));
        $agent = Agent::make();
        $agent->setAiProvider($first)->setInstructions('Original instructions')->addTool(new WeatherToolkit());
        $this->interact($agent, $mode);

        $agent->setAiProvider($second)->setInstructions('Updated instructions');
        $this->interact($agent, $mode);

        $first->assertCallCount(1);
        $second->assertCallCount(1);
        $second->assertMethodCallCount($mode, 1);
        $prompt = $second->getRecorded()[0]->systemPrompt->getContent();
        $this->assertStringContainsString('Updated instructions', $prompt);
        $this->assertStringNotContainsString('Original instructions', $prompt);
        $this->assertSame(1, substr_count($prompt, '<TOOLS-GUIDELINES>'));
        $this->assertStringContainsString('Always report temperatures in Celsius.', $prompt);
        $this->assertCount(4, $agent->getChatHistory()->getMessages());
    }

    #[DataProvider('modes')]
    public function test_inference_mode_does_not_leak_into_the_next_chat(string $mode): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('{"name":"First"}'), new AssistantMessage('Plain reply'));
        $agent = Agent::make();
        $agent->setAiProvider($provider);
        $this->interact($agent, $mode);

        $state = $agent->chat(new UserMessage('Plain chat'));

        $this->assertSame('Plain reply', $state->getMessage()->getContent());
        $this->assertSame('chat', $provider->getRecorded()[1]->method);
    }

    public function test_added_tool_is_advertised_and_executed_on_the_next_turn(): void
    {
        $call = ToolCall::make('search', 'call_1', ['query' => 'PHP']);
        $provider = new FakeAIProvider(
            new AssistantMessage('First reply'),
            new ToolCallMessage(null, [$call]),
            new AssistantMessage('Found PHP'),
        );
        $agent = Agent::make();
        $agent->setAiProvider($provider)->addTool(new WeatherToolkit());
        $agent->chat(new UserMessage('Hello'));

        $agent->addTool(new SearchTool());
        $agent->chat(new UserMessage('Search PHP'));

        $this->assertSame('Results for: PHP', $call->getResult());
        $this->assertCount(1, $provider->getRecorded()[0]->tools);
        $this->assertCount(2, $provider->getRecorded()[1]->tools);
        $this->assertSame(1, substr_count($provider->getRecorded()[1]->systemPrompt->getContent(), '<TOOLS-GUIDELINES>'));
    }

    #[DataProvider('modes')]
    public function test_replaced_tools_are_advertised_and_executed_on_the_next_turn(string $mode): void
    {
        $call = ToolCall::make('search', 'call_1', ['query' => 'PHP']);
        $provider = new FakeAIProvider(
            new AssistantMessage('{"name":"First"}'),
            new ToolCallMessage(null, [$call]),
            new AssistantMessage('{"name":"Second"}'),
            new AssistantMessage('{"name":"Third"}'),
        );
        $agent = new WeatherAgent();
        $agent->setAiProvider($provider)->setInstructions('Application instructions');
        $this->interact($agent, $mode);

        $search = (new SearchTool())->setDescription('Search application documents');
        $agent->setTools([$search]);
        $this->interact($agent, $mode);

        $this->assertSame('Results for: PHP', $call->getResult());
        $this->assertSame([$search], $provider->getRecorded()[1]->tools);
        $this->assertSame([$search], $provider->getRecorded()[2]->tools);
        $this->assertSame('Application instructions', $provider->getRecorded()[1]->systemPrompt->getContent());

        $agent->setTools([]);
        $this->interact($agent, $mode);
        $this->assertSame([], $provider->getRecorded()[3]->tools);
    }

    public function test_replacing_tools_during_streaming_preserves_the_current_tool_loop(): void
    {
        $call = ToolCall::make('search', 'call_1', ['query' => 'PHP']);
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$call]),
            new AssistantMessage('Found PHP'),
            new AssistantMessage('Next turn'),
        );
        $agent = new WeatherAgent();
        $search = new SearchTool();
        $agent->setAiProvider($provider)->setTools([$search]);
        $stream = $agent->stream(new UserMessage('Search PHP'));
        $stream->rewind();

        $agent->setTools([]);
        iterator_to_array($stream);

        $this->assertSame('Results for: PHP', $call->getResult());
        $this->assertSame([$search], $provider->getRecorded()[1]->tools);

        $agent->chat(new UserMessage('Next turn'));
        $this->assertSame([], $provider->getRecorded()[2]->tools);
    }

    public function test_tool_run_limit_changes_during_streaming_apply_on_the_next_turn(): void
    {
        $firstCall = ToolCall::make('search', 'call_1', ['query' => 'PHP']);
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$firstCall]),
            new AssistantMessage('Found PHP'),
            new ToolCallMessage(null, [ToolCall::make('search', 'call_2', ['query' => 'PHP'])]),
        );
        $agent = Agent::make();
        $agent->setAiProvider($provider)->addTool(new SearchTool());
        $stream = $agent->stream(new UserMessage('Search PHP'));
        $stream->rewind();

        $agent->toolMaxRuns(0);
        iterator_to_array($stream);
        $this->assertSame('Results for: PHP', $firstCall->getResult());

        $this->expectException(ToolRunsExceededException::class);
        $agent->chat(new UserMessage('Search PHP again'));
    }

    public function test_tool_error_handler_changes_during_streaming_apply_on_the_next_turn(): void
    {
        $firstCall = ToolCall::make('failing_tool', 'call_1', ['input' => 'test']);
        $secondCall = ToolCall::make('failing_tool', 'call_2', ['input' => 'test']);
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [$firstCall]),
            new AssistantMessage('Recovered'),
            new ToolCallMessage(null, [$secondCall]),
            new AssistantMessage('Recovered again'),
        );
        $agent = Agent::make();
        $agent->setAiProvider($provider)->addTool(new AgentFailingTool());
        $agent->toolErrorHandler(static fn (Throwable $error, ToolCall $tool): string => 'Original handler');
        $stream = $agent->stream(new UserMessage('Use the tool'));
        $stream->rewind();

        $agent->toolErrorHandler(static fn (Throwable $error, ToolCall $tool): string => 'Updated handler');
        iterator_to_array($stream);
        $this->assertSame('Original handler', $firstCall->getResult());

        $agent->chat(new UserMessage('Use the tool again'));
        $this->assertSame('Updated handler', $secondCall->getResult());
    }

    public function test_parallel_tool_configuration_changes_preserve_added_nodes(): void
    {
        $node = new FirstNode();
        $middleware = new RecordingAgentMiddleware();
        $agent = Agent::make();
        $agent->addNode($node);
        $agent->setAiProvider(new FakeAIProvider(
            new AssistantMessage('First reply'),
            new AssistantMessage('Second reply'),
            new AssistantMessage('Third reply'),
        ));
        $toolNodes = [];
        $agent->subscribe(WorkflowStart::class, function (WorkflowStart $event) use (&$toolNodes): void {
            $toolNodes[] = $event->eventNodeMap[ToolCallEvent::class]::class;
        });
        $stream = $agent->stream(new UserMessage('Hello'));
        $stream->rewind();
        $agent->parallelToolCalls(true);
        iterator_to_array($stream);

        $agent->addMiddleware(ChatNode::class, fn () => $middleware);
        $agent->chat(new UserMessage('Parallel'));
        $this->assertInstanceOf(ParallelToolNode::class, \NeuronAI\Tests\Support\ExecutionTestFactory::runtime($agent)->getNodeForEvent(ToolCallEvent::class));
        $this->assertEquals($node, \NeuronAI\Tests\Support\ExecutionTestFactory::runtime($agent)->getNodeForEvent(StartEvent::class));

        $agent->parallelToolCalls(false)->chat(new UserMessage('Sequential'));
        $this->assertSame(ToolNode::class, \NeuronAI\Tests\Support\ExecutionTestFactory::runtime($agent)->getNodeForEvent(ToolCallEvent::class)::class);
        $this->assertSame(2, $middleware->agentCalls);
        $this->assertSame(2, $middleware->afterCalls);
        $this->assertSame([ToolNode::class, ParallelToolNode::class, ToolNode::class], $toolNodes);
        $this->assertEquals($node, \NeuronAI\Tests\Support\ExecutionTestFactory::runtime($agent)->getNodeForEvent(StartEvent::class));
    }
    public function test_configuration_changes_during_streaming_apply_to_the_next_segment(): void
    {
        $first = new FakeAIProvider(new AssistantMessage('First reply'));
        $second = new FakeAIProvider(new AssistantMessage('Second reply'));
        $agent = Agent::make();
        $agent->setAiProvider($first)->setInstructions('Original instructions');
        $stream = $agent->stream(new UserMessage('Hello'));
        $stream->rewind();

        $agent->setAiProvider($second)->setInstructions('Updated instructions')->addTool(new SearchTool());
        iterator_to_array($stream);
        $this->assertSame('First reply', $stream->getReturn()->getMessage()->getContent());
        $first->assertSystemPrompt('Original instructions');
        $second->assertNothingSent();

        $agent->chat(new UserMessage('Next turn'));
        $second->assertSystemPrompt('Updated instructions');
        $second->assertToolsConfigured(['search']);
    }

    public function test_store_changes_during_streaming_preserve_the_active_conversation(): void
    {
        $firstStore = new InMemoryMessageStore();
        $nextStore = new InMemoryMessageStore();
        $provider = new FakeAIProvider(
            new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'PHP'])]),
            new AssistantMessage('Found PHP'),
            new AssistantMessage('New conversation'),
        );
        $agent = Agent::make(workflowId: 'first-thread');
        $agent->setAiProvider($provider)->addTool(new SearchTool());
        $agent->setMessageStore($firstStore);
        $stream = $agent->stream(new UserMessage('Search PHP'));
        $stream->rewind();

        $agent->setMessageStore($nextStore);
        iterator_to_array($stream);

        $this->assertSame('first-thread', $stream->getReturn()->getWorkflowId());
        $this->assertCount(4, $firstStore->loadActive('first-thread'));
        $this->assertSame([], $nextStore->loadAll('first-thread'));
        $this->assertNull(Agent::make(workflowId: 'first-thread')->setPersistence($agent->getPersistence())->inspect());

        $state = $agent->chat(new UserMessage('Hello'));
        $this->assertSame('first-thread', $state->getWorkflowId());
        $this->assertCount(2, $nextStore->loadActive('first-thread'));
        $this->assertCount(4, $firstStore->loadActive('first-thread'));
    }

    public function test_resume_uses_current_provider_but_preserves_recorded_instructions_and_intent(): void
    {
        $first = new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('search', 'call_1', ['query' => 'PHP']),
        ]));
        $second = new FakeAIProvider(new AssistantMessage('Resumed reply'), new AssistantMessage('Next reply'));
        $agent = Agent::make(workflowId: 'thread-config');
        $agent->setAiProvider($first)->setInstructions('Original instructions')
            ->addTool((new SearchTool())->requireApproval());
        $stream = $agent->stream(new UserMessage('Search PHP'));
        iterator_to_array($stream);
        $this->assertTrue($stream->getReturn()->isInterrupted());
        $runId = $agent->inspect()?->runId;

        $agent->setAiProvider($second)->setInstructions('Updated instructions');
        $state = $agent->submitInputs(['call_1' => 'approve'], new ApprovalTranslator())->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($runId, $state->getRunId());
        $this->assertSame('stream', $second->getRecorded()[0]->method);
        $this->assertSame('Original instructions', $second->getRecorded()[0]->systemPrompt->getContent());

        $agent->chat(new UserMessage('Next turn'));
        $this->assertSame('chat', $second->getRecorded()[1]->method);
        $this->assertSame('Updated instructions', $second->getRecorded()[1]->systemPrompt->getContent());
    }

    public function test_the_context_window_hook_sizes_the_conversation(): void
    {
        $messages = new InMemoryMessageStore();
        $agent = $this->agentWithContextWindowHook(500)->setMessageStore($messages);

        $this->addConversation($agent->getChatHistory());

        $this->assertLessThan(10, count($messages->loadActive('thread')));
        $this->assertCount(10, $messages->loadAll('thread'));
    }

    public function test_an_explicit_context_window_wins_over_the_hook(): void
    {
        $messages = new InMemoryMessageStore();
        $agent = $this->agentWithContextWindowHook(500)->setMessageStore($messages)->setContextWindow(10_000);

        $this->addConversation($agent->getChatHistory());

        $this->assertCount(10, $messages->loadActive('thread'));
    }

    protected function agentWithContextWindowHook(int $tokens): Agent
    {
        return new class ($tokens) extends Agent {
            public function __construct(protected int $tokens)
            {
                parent::__construct('thread');
            }

            protected function contextWindow(): int
            {
                return $this->tokens;
            }
        };
    }

    /**
     * Ten alternating messages whose reported usage reaches 1150 tokens.
     */
    protected function addConversation(ChatHistory $history): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $history->addMessage($i % 2 === 0
                ? (new AssistantMessage("Answer {$i}"))->setUsage(new Usage(100 * $i, 150))
                : new UserMessage("Question {$i}"));
        }
    }
}
