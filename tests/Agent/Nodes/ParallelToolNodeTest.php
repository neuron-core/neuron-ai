<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Nodes;

use NeuronAI\Agent\InferenceRequest;
use NeuronAI\Tests\Support\AgentResourcesFactory;
use NeuronAI\Tests\Agent\Nodes\Stub\ParallelAnotherTool;
use NeuronAI\Tests\Agent\Nodes\Stub\ParallelRegularTool;
use NeuronAI\Workflow\NodeContext;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Nodes\ParallelToolNode;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Exceptions\ToolRunsExceededException;
use NeuronAI\Tests\Agent\Stub\TestParametrizedTool;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tests\Support\WorkflowTestStore;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Agent\Stub\AgentFailingTool;
use NeuronAI\Tests\Agent\Stub\SearchTool;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Fork\Fork;
use Throwable;

use function array_map;
use function class_exists;
use function extension_loaded;
use function iterator_to_array;

class ParallelToolNodeTest extends TestCase
{
    public function test_parameterized_tools_tracked_by_run_key_in_parallel(): void
    {
        $registry = [new TestParametrizedTool('parallel_tool')];
        $calls = [
            ToolCall::make('parallel_tool', 'call_1', ['key' => 'id=1']),
            ToolCall::make('parallel_tool', 'call_2', ['key' => 'id=2']),
        ];

        $toolNode = new ParallelToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $calls);
        $request = new InferenceRequest(instructions: 'Test');
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

        $toolNode->setWorkflowContext(new NodeContext());

        foreach ($toolNode($event, $state, AgentResourcesFactory::make($registry)) as $_) {
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

        $toolNode = new ParallelToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $calls);
        $request = new InferenceRequest(instructions: 'Test');
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

        $toolNode->setWorkflowContext(new NodeContext());

        foreach ($toolNode($event, $state, AgentResourcesFactory::make($registry)) as $_) {
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

        $toolNode = new ParallelToolNode(maxRuns: 1);
        $state = new AgentState();
        $toolCallMessage = new ToolCallMessage(null, $calls);
        $request = new InferenceRequest(instructions: 'Test');
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

        $toolNode->setWorkflowContext(new NodeContext());

        $this->expectException(ToolRunsExceededException::class);
        $this->expectExceptionMessage('Tool bounded_tool has been executed too many times - 1');

        foreach ($toolNode($event, $state, AgentResourcesFactory::make($registry)) as $_) {
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
        $request = new InferenceRequest(instructions: 'Test');
        $state = new AgentState();
        $state->request = $request;
        $event = new ToolCallEvent($toolCallMessage);

        $state->setExecutionMetadata($runId, $runId, 1);

        // Run 1: the batch executes and its result is memoized mid-node.
        $node1 = new ParallelToolNode();
        $node1->setWorkflowContext(new NodeContext(null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));
        foreach ($node1($event, $state, AgentResourcesFactory::make($registry)) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'));
        $this->assertSame(1, $state->getToolRuns('another_tool'));

        // Recovery starts with stale counters and no live registry for cached calls.
        $state->resetToolRuns();
        $node2 = new ParallelToolNode(
            beforeChild: static function (): void {
                throw new RuntimeException('Child initialization must not repeat on recovery.');
            },
            afterChild: static function (): void {
                throw new RuntimeException('Child cleanup must not repeat on recovery.');
            },
        );
        $node2->setWorkflowContext(new NodeContext(null, false, WorkflowTestStore::memoizer($persistence, $runId, $stepId)));
        foreach ($node2($event, $state, AgentResourcesFactory::make()) as $_) {
            $_ = null; // This is to prevent rector from removing it.
        }

        $this->assertSame(1, $state->getToolRuns('regular_tool'), 'Parallel tools must not re-execute on recovery');
        $this->assertSame(1, $state->getToolRuns('another_tool'), 'Parallel tools must not re-execute on recovery');
    }

    /**
     * @param ToolCall[] $calls
     * @param ToolInterface[] $registry
     */
    protected function runParallel(array $calls, array $registry, ?callable $errorHandler = null): AgentState
    {
        if (!extension_loaded('pcntl') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Concurrent tool execution requires pcntl and spatie/fork.');
        }

        $state = new AgentState();
        $state->request = new InferenceRequest('Test');
        $node = new ParallelToolNode(errorHandler: $errorHandler);
        $node->setWorkflowContext(new NodeContext());

        iterator_to_array($node(new ToolCallEvent(new ToolCallMessage(null, $calls)), $state, AgentResourcesFactory::make($registry)), false);

        return $state;
    }

    /**
     * @return array<int, string|null>
     */
    protected function resultCallIds(AgentState $state): array
    {
        $message = $state->request->messages[1];
        $this->assertInstanceOf(ToolResultMessage::class, $message);

        return array_map(static fn (ToolCall $call): ?string => $call->getCallId(), $message->getToolCalls());
    }

    public function test_results_are_matched_to_their_calls_in_the_original_order(): void
    {
        $calls = [
            ToolCall::make('search', 'call_1', ['query' => 'first']),
            ToolCall::make('search', 'call_2', ['query' => 'second']),
            ToolCall::make('search', 'call_3', ['query' => 'third']),
        ];

        $state = $this->runParallel($calls, [new SearchTool()]);

        $this->assertSame(['call_1', 'call_2', 'call_3'], $this->resultCallIds($state));
        $this->assertSame(
            ['Results for: first', 'Results for: second', 'Results for: third'],
            array_map(static fn (ToolCall $call): string|ToolOutput => $call->getResult(), $calls)
        );
        $this->assertSame(3, $state->getToolRuns('search'));
    }

    public function test_rejected_calls_keep_their_position_and_never_run(): void
    {
        $rejected = ToolCall::make('search', 'call_2', ['query' => 'secret'])
            ->setApprovalState(ApprovalState::Rejected, 'no')
            ->setResult('TOOL NOT EXECUTED');
        $calls = [
            ToolCall::make('search', 'call_1', ['query' => 'first']),
            $rejected,
            ToolCall::make('search', 'call_3', ['query' => 'third']),
        ];

        $state = $this->runParallel($calls, [new SearchTool()]);

        $this->assertSame(['call_1', 'call_2', 'call_3'], $this->resultCallIds($state));
        $this->assertSame('Results for: first', $calls[0]->getResult());
        $this->assertSame('TOOL NOT EXECUTED', $rejected->getResult());
        $this->assertSame('Results for: third', $calls[2]->getResult());
        $this->assertSame(2, $state->getToolRuns('search'), 'A rejected call consumes no run slot');
    }

    public function test_a_failing_child_is_settled_by_the_error_handler_without_affecting_the_others(): void
    {
        $calls = [
            ToolCall::make('search', 'call_1', ['query' => 'first']),
            ToolCall::make('failing_tool', 'call_2', ['input' => 'boom']),
            ToolCall::make('search', 'call_3', ['query' => 'third']),
        ];
        $handled = [];

        $this->runParallel(
            $calls,
            [new SearchTool(), new AgentFailingTool()],
            function (Throwable $error, ToolCall $call) use (&$handled): ToolOutput {
                $handled[] = [$error::class, $error->getMessage(), $call->getCallId()];
                return ToolOutput::error('handled: ' . $error->getMessage());
            },
        );

        $this->assertSame([[RuntimeException::class, 'Tool failed!', 'call_2']], $handled);
        $this->assertSame('Results for: first', $calls[0]->getResult());
        $this->assertInstanceOf(ToolOutput::class, $calls[1]->getResult());
        $this->assertSame('handled: Tool failed!', $calls[1]->getResult()->getText());
        $this->assertSame('Results for: third', $calls[2]->getResult());
    }

    public function test_a_failing_child_without_a_handler_fails_the_node_with_the_original_exception(): void
    {
        $calls = [
            ToolCall::make('search', 'call_1', ['query' => 'first']),
            ToolCall::make('failing_tool', 'call_2', ['input' => 'boom']),
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tool failed!');

        $this->runParallel($calls, [new SearchTool(), new AgentFailingTool()]);
    }

    public function test_an_unregistered_tool_fails_before_any_child_starts(): void
    {
        $calls = [
            ToolCall::make('search', 'call_1', ['query' => 'first']),
            ToolCall::make('ghost', 'call_2'),
        ];

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('The tool ghost is not registered on this agent: the call cannot be executed.');

        $this->runParallel($calls, [new SearchTool()]);
    }
}
