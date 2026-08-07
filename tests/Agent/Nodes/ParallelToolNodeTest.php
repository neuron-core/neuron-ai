<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Workflow\NodeContext;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tests\Agent\Tools\TestParametrizedTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\StepMemoizer;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;

class ParallelRegularTool extends Tool
{
    protected string $name = 'regular_tool';

    protected ?string $description = 'A regular tool';

    public function __invoke(): string
    {
        return 'result';
    }
}

class ParallelAnotherTool extends Tool
{
    protected string $name = 'another_tool';

    protected ?string $description = 'Another tool';

    public function __invoke(): string
    {
        return 'result';
    }
}

class ParallelToolNodeTest extends TestCase
{
    public function test_parameterized_tools_tracked_by_run_key_in_parallel(): void
    {
        $registry = [new TestParametrizedTool('parallel_tool')];
        $calls = [
            ToolCall::make('parallel_tool', 'call_1', ['key' => 'id=1']),
            ToolCall::make('parallel_tool', 'call_2', ['key' => 'id=2']),
        ];

        $toolNode = new ParallelToolNode(new InMemoryChatHistory(), maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $calls);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: $registry);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        // Each unique parameter combination tracked separately
        $this->assertSame(1, $state->getToolRuns('parallel_tool:id=1'));
        $this->assertSame(1, $state->getToolRuns('parallel_tool:id=2'));
        $this->assertSame(0, $state->getToolRuns('parallel_tool'));
    }

    public function test_regular_tools_tracked_by_name_in_parallel(): void
    {
        $registry = [new ParallelRegularTool(), new ParallelAnotherTool()];
        $calls = [
            ToolCall::make('regular_tool', 'call_1', []),
            ToolCall::make('another_tool', 'call_2', []),
        ];

        $toolNode = new ParallelToolNode(new InMemoryChatHistory(), maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $calls);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: $registry);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(1, $state->getToolRuns('another_tool'));
    }

    public function test_max_runs_enforced_per_run_key_in_parallel(): void
    {
        $registry = [new TestParametrizedTool('bounded_tool')];
        $calls = [
            ToolCall::make('bounded_tool', 'call_1', ['key' => 'id=1']),
            ToolCall::make('bounded_tool', 'call_2', ['key' => 'id=1']),
        ];

        $toolNode = new ParallelToolNode(new InMemoryChatHistory(), maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $calls);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: $registry);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool bounded_tool has been attempted too many times: 1 attempts.');

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }
    }

    public function test_parallel_batch_not_re_executed_on_recovery(): void
    {
        // The concurrent execution path (the Spatie fork fan-out) is wrapped in a
        // durable memo. On crash recovery — a fresh step engine sharing the same
        // persistence — the node re-executes but the memoized batch must NOT
        // re-run: side-effecting tools stay at-most-once, and the run counters
        // (incremented inside the memo) must not advance a second time.
        $runId = 'parallel_recovery_test';
        $persistence = new InMemoryPersistence();
        $stepId = ParallelToolNode::class . '-0';

        $registry = [new ParallelRegularTool(), new ParallelAnotherTool()];
        $calls = [
            ToolCall::make('regular_tool', 'call_1', []),
            ToolCall::make('another_tool', 'call_2', []),
        ];

        $toolCallMessage = new ToolCallMessage(null, $calls);
        $inferenceEvent = new AIInferenceEvent(instructions: 'Test', tools: $registry);
        $event = new ToolCallEvent($toolCallMessage, $inferenceEvent);

        $state = new AgentState();
        $state->set('__runId', $runId);

        // Run 1: the batch executes and its result is memoized mid-node.
        $engine1 = new LocalStepEngine($persistence);
        $engine1->prepareExecution($runId);
        $node1 = new ParallelToolNode(new InMemoryChatHistory());
        $node1->setWorkflowContext(new NodeContext($state, $event, null, false, new StepMemoizer($engine1, $stepId)));
        foreach ($node1($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(1, $state->getToolRuns('another_tool'));

        // Recovery: brand-new engine, same persistence (simulates a process restart).
        $engine2 = new LocalStepEngine($persistence);
        $engine2->prepareExecution($runId);
        $node2 = new ParallelToolNode(new InMemoryChatHistory());
        $node2->setWorkflowContext(new NodeContext($state, $event, null, false, new StepMemoizer($engine2, $stepId)));
        foreach ($node2($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'), 'Parallel tools must not re-execute on recovery');
        $this->assertSame(1, $state->getToolRuns('another_tool'), 'Parallel tools must not re-execute on recovery');
    }
}
