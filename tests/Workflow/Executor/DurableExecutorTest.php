<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\CountableNode;
use NeuronAI\Tests\Workflow\Executor\Stub\DurableInterruptNodeB;
use NeuronAI\Tests\Workflow\Executor\Stub\DurableNodeA;
use NeuronAI\Tests\Workflow\Executor\Stub\DurableNodeB;
use NeuronAI\Tests\Workflow\Executor\Stub\DurableNodeC;
use NeuronAI\Tests\Workflow\Executor\Stub\MemoizingNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DurableExecutorTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function setUp(): void
    {
        CountableNode::resetExecutionCount();
    }

    public function test_memoization_on_crash_recovery(): void
    {
        $workflowId = 'durable_crash_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crush when run
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        // Node A executed (completed), node B executed and crashed, node C never ran
        $crashedRunExecutions = CountableNode::getExecutionCount();

        // Revive with the same workflow ID — node B won't crash this time
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $failedRunId = $this->configure($workflow2, $persistence)->inspect()?->runId;
        $result = $this->resume($workflow2, $persistence, null);

        // Node A should be memoized (skipped), nodes B and C execute fresh
        $this->assertSame(2, $crashedRunExecutions);
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertSame($failedRunId, $result->getRunId());
        $this->assertSame(2, $result->getExecutionAttempt());
        $this->assertTrue($result->get('step_a_executed'));
        $this->assertTrue($result->get('step_b_executed'));
        $this->assertTrue($result->get('step_c_executed'));
    }

    public function test_invalid_completed_step_record_does_not_reexecute_the_node(): void
    {
        $workflowId = 'durable_invalid_step_test';
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true),
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException) {
            $control = $persistence->get($workflowId, '__control');
            $this->assertNotNull($control);
            $this->assertTrue($persistence->writeIfUnchanged(
                $workflowId,
                '__control',
                $control,
                [
                    $this->stepKey($workflow, DurableNodeA::class . '-0')
                        => $serializer->serialize('not-a-step-result'),
                ],
            ));
        }

        CountableNode::resetExecutionCount();
        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        try {
            $this->resume($workflow, $persistence, null);
            $this->fail('Expected PersistenceException');
        } catch (PersistenceException $e) {
            $this->assertStringContainsString('Invalid step record', $e->getMessage());
        }

        $this->assertSame(0, CountableNode::getExecutionCount());
    }

    public function test_interrupt_then_resume_memoizes_completed_steps(): void
    {
        $workflowId = 'durable_interrupt_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        // First run — pauses at node B
        $state = $this->execute(
            $workflow,
            $persistence,
        );

        $this->assertTrue($state->isInterrupted());
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($state->get('step_a_executed'));
        $this->assertTrue($state->get('step_b_executed'));
        // Paused steps are retained for resume (cleanup only runs on completion)
        $this->assertNotNull($persistence->get($workflowId, $this->stepKey($workflow, DurableNodeA::class . '-0')));

        // Resume — deliver the payload (node B just checks isResuming()).
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->resume(
            $workflow2,
            $persistence,
            ['approve_b' => 'approve'],
        );

        // Node A memoized (skipped), node B resumes, node C executes
        // That's 2 executions (B and C)
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($result->get('step_b_resumed'));
        $this->assertTrue($result->get('step_c_executed'));
    }

    public function test_step_cleanup_after_completion(): void
    {
        $workflowId = 'durable_cleanup_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $this->execute($workflow, $persistence);

        // Steps should be deleted after successful completion
        $this->assertNull($persistence->get($workflowId, $this->stepKey($workflow, DurableNodeA::class . '-0')));
        $this->assertNull($persistence->get($workflowId, $this->stepKey($workflow, DurableNodeB::class . '-1')));
        $this->assertNull($persistence->get($workflowId, $this->stepKey($workflow, DurableNodeC::class . '-2')));
    }

    public function test_steps_not_cleaned_up_after_crash(): void
    {
        $workflowId = 'durable_crash_cleanup_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crush when run
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException) {
            // After crash, completed steps should still be persisted
            $this->assertNotNull($persistence->get($workflowId, $this->stepKey($workflow, DurableNodeA::class . '-0')));
        }
    }

    public function test_default_persistence_frees_the_workflow_id_on_completion(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $first = $this->execute($workflow);
        $this->assertNull($workflow->inspect());
        $second = $workflow->run();

        $this->assertTrue($first->get('node_one_executed'));
        $this->assertTrue($first->get('node_two_executed'));
        $this->assertTrue($first->get('node_three_executed'));
        $this->assertSame(WorkflowStatus::Completed, $second->getStatus());
        $this->assertNotSame($first->getRunId(), $second->getRunId());
    }

    public function test_memoize_runs_once_across_mid_node_crash_and_recovery(): void
    {
        MemoizingNode::resetOperationCount();
        CountableNode::resetExecutionCount();

        $workflowId = 'durable_memoize_test';
        $persistence = new InMemoryPersistence();

        // Run 1: the memoized operation runs, its value is persisted mid-node,
        // then the node crashes before returning.
        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([new MemoizingNode(shouldCrash: true)]);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash after memoize', $e->getMessage());
        }

        // The expensive operation ran exactly once, even though the node crashed.
        $this->assertSame(1, MemoizingNode::getOperationCount());

        // Revive with a fresh executor + same persistence (simulates process restart).
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(workflowId: $workflowId)
            ->addNodes([new MemoizingNode(shouldCrash: false)]);

        $result = $this->resume($workflow2, $persistence, null);

        // The node re-executed (it never completed before), but the memoized operation
        // did NOT run again — it was replayed from the persisted memo.
        $this->assertSame(1, CountableNode::getExecutionCount());
        $this->assertSame(1, MemoizingNode::getOperationCount());
        $this->assertSame('computed_1', $result->get('memo_result'));
    }

    public function test_crash_marks_the_run_failed_without_a_step_record(): void
    {
        $workflowId = 'durable_failed_marker_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: $workflowId)
            ->addNodes([new MemoizingNode(shouldCrash: true)]);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $this->assertNull($persistence->get(
            $workflowId,
            $this->stepKey($workflow, MemoizingNode::class . '-0'),
        ));
        $raw = $persistence->get($workflowId, '__control');
        $this->assertNotNull($raw);
        $control = (new PhpSerializer())->unserialize($raw);
        $this->assertInstanceOf(WorkflowControl::class, $control);
        $this->assertSame(WorkflowStatus::Failed, $control->status);
    }
}
