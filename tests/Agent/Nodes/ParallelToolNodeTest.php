<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Tests\Agent\Nodes\Stub\ParallelAnotherTool;
use NeuronAI\Tests\Agent\Nodes\Stub\ParallelRegularTool;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tests\Agent\Stub\TestParametrizedTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
        $request = new InferenceRequest(instructions: 'Test', tools: $registry);
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

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
        $request = new InferenceRequest(instructions: 'Test', tools: $registry);
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

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
        $request = new InferenceRequest(instructions: 'Test', tools: $registry);
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

        $toolNode->setWorkflowContext(new NodeContext($state, $event));

        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool bounded_tool has been executed too many times - 1');

        foreach ($toolNode($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }
    }

    public function test_parallel_batch_not_re_executed_on_recovery(): void
    {
        // The concurrent execution path (the Spatie fork fan-out) is wrapped in a
        // durable memo. On crash recovery — a fresh step engine sharing the same
        // persistence — the node restores the memoized batch without executing
        // it again, and reconstructs its counters from an older state snapshot.
        $runId = 'parallel_recovery_test';
        $persistence = new InMemoryPersistence();
        $stepId = ParallelToolNode::class . '-0';

        $registry = [new ParallelRegularTool(), new ParallelAnotherTool()];
        $calls = [
            ToolCall::make('regular_tool', 'call_1', []),
            ToolCall::make('another_tool', 'call_2', []),
        ];

        $toolCallMessage = new ToolCallMessage(null, $calls);
        $request = new InferenceRequest(instructions: 'Test', tools: $registry);
        $state = new AgentState();
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

        $state->setExecutionMetadata($runId, $runId, 1);

        // Run 1: the batch executes and its result is memoized mid-node.
        $node1 = new ParallelToolNode(new InMemoryChatHistory());
        $node1->setWorkflowContext(new NodeContext($state, $event, null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));
        foreach ($node1($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(1, $state->getToolRuns('another_tool'));

        // Recovery starts with stale counters and no live registry for cached calls.
        $state->resetToolRuns();
        $state->request->tools = [];
        $node2 = new ParallelToolNode(
            new InMemoryChatHistory(),
            beforeChild: static function (): void {
                throw new RuntimeException('Child initialization must not repeat on recovery.');
            },
            afterChild: static function (): void {
                throw new RuntimeException('Child cleanup must not repeat on recovery.');
            },
        );
        $node2->setWorkflowContext(new NodeContext($state, $event, null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));
        foreach ($node2($event, $state) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'), 'Parallel tools must not re-execute on recovery');
        $this->assertSame(1, $state->getToolRuns('another_tool'), 'Parallel tools must not re-execute on recovery');
    }
}
