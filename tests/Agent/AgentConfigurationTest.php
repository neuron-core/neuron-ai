<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ChatNode;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tests\Agent\Memory\Stub\InspectableMemory;
use NeuronAI\Tests\Agent\Middleware\Stub\RecordingAgentMiddleware;
use NeuronAI\Tests\Agent\Stub\AgentFailingTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tests\Agent\Stub\WeatherToolkit;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tests\Workflow\Stub\FirstNode;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\StartEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

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

    public function test_tool_run_limit_changes_apply_on_the_next_turn(): void
    {
        $provider = new FakeAIProvider(
            new AssistantMessage('First reply'),
            new ToolCallMessage(null, [ToolCall::make('search', 'call_1', ['query' => 'PHP'])]),
            new AssistantMessage('Should not be reached'),
        );
        $agent = Agent::make();
        $agent->setAiProvider($provider)->addTool(new SearchTool());
        $agent->chat(new UserMessage('Hello'));
        $agent->toolMaxRuns(0);

        $this->expectException(ToolRunsExceededException::class);
        $agent->chat(new UserMessage('Search PHP'));
    }

    public function test_tool_error_handler_changes_apply_on_the_next_turn(): void
    {
        $call = ToolCall::make('failing_tool', 'call_1', ['input' => 'test']);
        $provider = new FakeAIProvider(
            new AssistantMessage('First reply'),
            new ToolCallMessage(null, [$call]),
            new AssistantMessage('Recovered'),
        );
        $agent = Agent::make();
        $agent->setAiProvider($provider)->addTool(new AgentFailingTool());
        $agent->chat(new UserMessage('Hello'));
        $agent->toolErrorHandler(static fn (Throwable $error, ToolCall $tool): string => 'Handled failure');

        $agent->chat(new UserMessage('Use the tool'));

        $this->assertSame('Handled failure', $call->getResult());
    }

    public function test_memory_can_be_added_and_replaced_after_execution(): void
    {
        $first = new InspectableMemory();
        $second = new InspectableMemory();
        $agent = Agent::make(threadId: 'thread-config');
        $agent->setAiProvider(new FakeAIProvider(
            new AssistantMessage('First reply'),
            new AssistantMessage('Second reply'),
            new AssistantMessage('Third reply'),
        ));
        $agent->chat(new UserMessage('No memory'));
        $agent->setMemory($first)->chat(new UserMessage('First memory'));
        $agent->setMemory($second)->chat(new UserMessage('Second memory'));

        $this->assertSame([['thread-config', 'First memory', 'Second reply']], $first->remembered);
        $this->assertSame([['thread-config', 'Second memory', 'Third reply']], $second->remembered);
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
        $agent->chat(new UserMessage('Hello'));
        $agent->addMiddleware(ChatNode::class, $middleware);
        $agent->parallelToolCalls(true)->chat(new UserMessage('Parallel'));
        $this->assertInstanceOf(ParallelToolNode::class, $agent->getNodeForEvent(ToolCallEvent::class));
        $this->assertSame($node, $agent->getNodeForEvent(StartEvent::class));

        $agent->parallelToolCalls(false)->chat(new UserMessage('Sequential'));
        $this->assertSame(ToolNode::class, $agent->getNodeForEvent(ToolCallEvent::class)::class);
        $this->assertSame(2, $middleware->agentCalls);
        $this->assertSame(2, $middleware->afterCalls);
        $this->assertSame($node, $agent->getNodeForEvent(StartEvent::class));
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

    public function test_resume_uses_current_provider_but_preserves_recorded_instructions_and_intent(): void
    {
        $memory = new InspectableMemory();
        $first = new FakeAIProvider(new ToolCallMessage(null, [
            ToolCall::make('search', 'call_1', ['query' => 'PHP']),
        ]));
        $second = new FakeAIProvider(new AssistantMessage('Resumed reply'), new AssistantMessage('Next reply'));
        $agent = Agent::make(threadId: 'thread-config');
        $agent->setAiProvider($first)->setInstructions('Original instructions')
            ->setMemory($memory)->addTool((new SearchTool())->requireApproval());
        $stream = $agent->stream(new UserMessage('Search PHP'));
        iterator_to_array($stream);
        $this->assertTrue($stream->getReturn()->isInterrupted());
        $runId = $agent->getRunId();

        $agent->setAiProvider($second)->setInstructions('Updated instructions')->setMemoryUsage(false, false);
        $state = $agent->toolApprovalDecisions(['call_1' => 'approve'])->run();

        $this->assertFalse($state->isInterrupted());
        $this->assertSame($runId, $agent->getRunId());
        $this->assertSame('stream', $second->getRecorded()[0]->method);
        $this->assertSame('Original instructions', $second->getRecorded()[0]->systemPrompt->getContent());
        $this->assertSame([['thread-config', 'Search PHP', 'Resumed reply']], $memory->remembered);

        $agent->chat(new UserMessage('Next turn'));
        $this->assertSame('chat', $second->getRecorded()[1]->method);
        $this->assertSame('Updated instructions', $second->getRecorded()[1]->systemPrompt->getContent());
        $this->assertCount(1, $memory->remembered);
        $this->assertSame(['Search PHP'], $memory->recalls);
    }

    public function test_memory_usage_changes_apply_to_a_new_run(): void
    {
        $memory = new InspectableMemory();
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider(new AssistantMessage('First reply'), new AssistantMessage('Second reply')))
            ->setMemory($memory);
        $agent->chat(new UserMessage('Hello'));

        $agent->setMemoryUsage(false, false)->run();

        $this->assertCount(1, $memory->remembered);
        $this->assertSame(['Hello'], $memory->recalls);
    }

}
