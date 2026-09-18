<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentOutputEvent;
use NeuronAI\Agent\Nodes\AgentEndNode;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Testing\FakeMiddleware;
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

use function iterator_to_array;

class AgentOutputTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        foreach (['chat', 'stream', 'structured'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('modes')]
    public function test_custom_exit_runs_once_after_final_response(string $mode): void
    {
        $text = $mode === 'structured' ? '{"name":"Ada"}' : 'Hello Ada.';
        $provider = new FakeAIProvider(new AssistantMessage($text));
        $agent = OutputAgent::make(threadId: 'output-test');
        $agent->setAiProvider($provider);
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

    public function test_failed_exit_recovers_on_a_fresh_instance_without_repeating_inference(): void
    {
        $provider = new FakeAIProvider(new AssistantMessage('Hello.'));
        $first = OutputAgent::make(threadId: 'output-recovery');
        $first->setAiProvider($provider);
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
        $second->setPersistence($first->getPersistence());
        $second->setChatHistory($first->getChatHistory());
        $state = $second->run();
        $this->assertSame($first->getRunId(), $second->getRunId());
        $this->assertSame(WorkflowStatus::Completed, $state->getStatus());
        $this->assertSame('Hello.', $state->get('output'));
        $this->assertSame(1, $provider->getCallCount());
        $this->assertCount(2, $second->getChatHistory()->getMessages());
    }

    public function test_default_graph_routes_all_completion_paths_through_the_end_node(): void
    {
        $agent = Agent::make();
        $agent->setAiProvider(new FakeAIProvider());
        $agent->bootstrap();
        $this->assertInstanceOf(AgentEndNode::class, $agent->getEventNodeMap()[AgentOutputEvent::class]);
        $graph = (new WorkflowGraphBuilder())->build($agent->getStartEvent()::class, $agent->getEventNodeMap());
        $edges = [];
        foreach ($graph->getEdges() as $edge) {
            $edges[] = [$graph->getVertex($edge->from)->label, $graph->getVertex($edge->to)->label];
        }
        foreach (['ChatNode', 'StructuredOutputNode'] as $node) {
            $this->assertContains([$node, 'AgentOutputEvent'], $edges);
            $this->assertNotContains([$node, 'StopEvent'], $edges);
        }
        $this->assertContains(['AgentOutputEvent', 'AgentEndNode'], $edges);
        $this->assertContains(['AgentEndNode', 'StopEvent'], $edges);
    }
}
