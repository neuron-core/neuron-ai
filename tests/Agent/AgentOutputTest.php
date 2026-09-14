<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Events\StoreMemoryEvent;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Agent\Nodes\StoreMemoryNode;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMiddleware;
use NeuronAI\Tests\Agent\Memory\Stub\InspectableMemory;
use NeuronAI\Tests\Agent\Stub\GetWeatherTool;
use NeuronAI\Tests\Agent\Stub\OutputAgent;
use NeuronAI\Tests\Agent\Stub\OutputNode;
use NeuronAI\Tests\StructuredOutput\Stub\User;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Exporter\WorkflowGraphBuilder;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AgentOutputTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function modes(): iterable
    {
        foreach (['chat', 'stream', 'structured'] as $mode) {
            foreach (['absent', 'enabled', 'disabled'] as $memory) {
                yield "{$mode}/{$memory}" => [$mode, $memory];
            }
        }
    }

    #[DataProvider('modes')]
    public function test_custom_exit_runs_once_after_final_response_and_optional_memory(string $mode, string $memoryUsage): void
    {
        $text = $mode === 'structured' ? '{"name":"Ada"}' : 'Hello Ada.';
        $provider = new FakeAIProvider(new AssistantMessage($text));
        $memory = new InspectableMemory();
        $agent = OutputAgent::make(threadId: 'output-test');
        $agent->setAiProvider($provider);
        if ($memoryUsage !== 'absent') {
            $agent->setMemory($memory)->setMemoryUsage(false, $memoryUsage === 'enabled');
        }
        $agent->addMiddleware(OutputNode::class, (new FakeMiddleware())->setBeforeHandler(
            function () use ($memory, $memoryUsage): void {
                $this->assertCount($memoryUsage === 'enabled' ? 1 : 0, $memory->remembered);
            },
        ));
        $input = new UserMessage('Hello');
        if ($mode === 'structured') {
            $this->assertSame('Ada', $agent->structured($input, User::class)->name);
        } elseif ($mode === 'stream') {
            $stream = $agent->stream($input);
            iterator_to_array($stream);
            $this->assertSame($agent->getState(), $stream->getReturn());
        } else {
            $agent->chat($input);
        }
        $state = $agent->getState();
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame($text, $state->get('output'));
        $this->assertSame(1, $state->get('output_runs'));
        $this->assertSame(OutputNode::class, $agent->getEventNodeMap()[AgentOutputEvent::class]::class);
        $this->assertSame(1, $provider->getCallCount());
    }

    /** @return iterable<string, array{Message[]}> */
    public static function skippedExchanges(): iterable
    {
        yield 'no user' => [[new AssistantMessage('Hello')]];
        yield 'no assistant' => [[new UserMessage('Hello')]];
        yield 'empty user' => [[new UserMessage(null), new AssistantMessage('Hello')]];
        yield 'empty assistant' => [[new UserMessage('Hello'), new AssistantMessage(null)]];
    }

    /** @param Message[] $messages */
    #[DataProvider('skippedExchanges')]
    public function test_memory_skip_still_hands_off_to_output(array $messages): void
    {
        $memory = new InspectableMemory();
        $node = new StoreMemoryNode($memory, new InMemoryChatHistory());
        $stream = $node(new StoreMemoryEvent($messages), new AgentState());
        iterator_to_array($stream);
        $this->assertInstanceOf(AgentOutputEvent::class, $stream->getReturn());
        $this->assertSame([], $memory->remembered);
    }

    public function test_output_waits_until_tool_approval_and_final_inference(): void
    {
        $agent = OutputAgent::make();
        $agent->setAiProvider(new FakeAIProvider(
            new ToolCallMessage(null, [new ToolCall('get_weather', 'weather-1', ['location' => 'Rome'])]),
            new AssistantMessage('Sunny.'),
        ));
        $agent->addTool(GetWeatherTool::make()->requireApproval());
        $state = $agent->chat(new UserMessage('Weather?'));
        $this->assertTrue($state->isInterrupted());
        $this->assertFalse($state->has('output'));
        $state = $agent->submitApprovalDecisions(['weather-1' => 'approve'])->run();
        $this->assertSame('Sunny.', $state->get('output'));
        $this->assertSame(1, $state->get('output_runs'));
    }

    public function test_failed_exit_recovers_on_a_fresh_instance_without_repeating_inference_or_memory(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        $memory = new InspectableMemory();
        $first = OutputAgent::make(threadId: 'output-recovery');
        $first->setAiProvider($provider);
        $first->setMemory($memory);
        $first->addMiddleware(OutputNode::class, (new FakeMiddleware())->setThrowOnBefore(new RuntimeException('Output failed.')));
        try {
            $first->chat(new UserMessage('Hello'));
            $this->fail('Expected output failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('Output failed.', $error->getMessage());
        }
        $this->assertSame(WorkflowStatus::Failed, $first->getState()->getStatus());
        $second = OutputAgent::make(workflowId: $first->getWorkflowId());
        $second->setAiProvider($provider);
        $second->setMemory($memory);
        $second->setPersistence($first->getPersistence());
        $second->setChatHistory($first->getChatHistory());
        $state = $second->run();
        $this->assertSame($first->getRunId(), $second->getRunId());
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame('Hello.', $state->get('output'));
        $this->assertSame(1, $provider->getCallCount());
        $this->assertCount(1, $memory->remembered);
        $this->assertCount(2, $second->getChatHistory()->getMessages());
    }

    public function test_default_graph_routes_all_completion_paths_through_the_end_node(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider());
        $agent->setMemory(new InspectableMemory());
        $agent->bootstrap();
        $this->assertInstanceOf(AgentEndNode::class, $agent->getEventNodeMap()[AgentOutputEvent::class]);
        $graph = (new WorkflowGraphBuilder())->build($agent->getStartEvent()::class, $agent->getEventNodeMap());
        $edges = [];
        foreach ($graph->getEdges() as $edge) {
            $edges[] = [$graph->getVertex($edge->from)->label, $graph->getVertex($edge->to)->label];
        }
        foreach (['ChatNode', 'StructuredOutputNode', 'StoreMemoryNode'] as $node) {
            $this->assertContains([$node, 'AgentOutputEvent'], $edges);
            $this->assertNotContains([$node, 'StopEvent'], $edges);
        }
        $this->assertContains(['AgentOutputEvent', 'AgentEndNode'], $edges);
        $this->assertContains(['AgentEndNode', 'StopEvent'], $edges);
    }
}
