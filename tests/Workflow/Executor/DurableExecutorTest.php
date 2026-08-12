<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\CountableNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableInterruptNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeA;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeC;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableEventA;
use NeuronAI\Tests\Workflow\Executor\Stubs\MemoizingNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\RestoreSpyWorkflow;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\StepResult;
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

    public function testMemoizationOnCrashRecovery(): void
    {
        $runId = 'durable_crash_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: $runId)
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
        $this->assertSame(2, CountableNode::getExecutionCount());

        // Revive at the same address — node B won't crash this time
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(address: $runId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->resume($workflow2, $persistence, null);

        // Node A should be memoized (skipped), nodes B and C execute fresh
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($result->get('step_a_executed'));
        $this->assertTrue($result->get('step_b_executed'));
        $this->assertTrue($result->get('step_c_executed'));
    }

    public function testInterruptThenResumeMemoizesCompletedSteps(): void
    {
        $runId = 'durable_interrupt_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: $runId)
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
        $this->assertNotNull($persistence->get($runId, $workflow->getRunId() . '/' . DurableNodeA::class . '-0'));

        // Resume — deliver the payload (node B just checks isResuming()).
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(address: $runId)
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

    public function testStepCleanupAfterCompletion(): void
    {
        $runId = 'durable_cleanup_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: $runId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $this->execute($workflow, $persistence);

        // Steps should be deleted after successful completion
        $this->assertNull($persistence->get($runId, $workflow->getRunId() . '/' . DurableNodeA::class . '-0'));
        $this->assertNull($persistence->get($runId, DurableNodeB::class . '-1'));
        $this->assertNull($persistence->get($runId, DurableNodeC::class . '-2'));
    }

    public function testStepsNotCleanedUpAfterCrash(): void
    {
        $runId = 'durable_crash_cleanup_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: $runId)
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
            $this->assertNotNull($persistence->get($runId, $workflow->getRunId() . '/' . DurableNodeA::class . '-0'));
        }
    }

    public function testDefaultInMemoryPersistence(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = $this->execute($workflow);

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testMemoizationWithFreshExecutor(): void
    {
        $runId = 'durable_fresh_engine_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: $runId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crash
                new DurableNodeC(),
            ]);

        // Run 1: Node A completes, node B crashes
        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $this->assertSame(2, CountableNode::getExecutionCount());

        // Revive with a fresh executor + same persistence (simulates process restart)
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(address: $runId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->resume($workflow2, $persistence, null);

        // Node A memoized via fresh executor, nodes B and C execute
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($result->get('step_a_executed'));
        $this->assertTrue($result->get('step_b_executed'));
        $this->assertTrue($result->get('step_c_executed'));
    }

    public function testMemoizeRunsOnceAcrossMidNodeCrashAndRecovery(): void
    {
        MemoizingNode::resetOperationCount();
        CountableNode::resetExecutionCount();

        $runId = 'durable_memoize_test';
        $persistence = new InMemoryPersistence();

        // Run 1: the memoized operation runs, its value is persisted mid-node,
        // then the node crashes before returning.
        $workflow = Workflow::make(address: $runId)
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
        $workflow2 = Workflow::make(address: $runId)
            ->addNodes([new MemoizingNode(shouldCrash: false)]);

        $result = $this->resume($workflow2, $persistence, null);

        // The node re-executed (it never completed before), but the memoized operation
        // did NOT run again — it was replayed from the persisted memo.
        $this->assertSame(1, CountableNode::getExecutionCount());
        $this->assertSame(1, MemoizingNode::getOperationCount());
        $this->assertSame('computed_1', $result->get('memo_result'));
    }

    public function testRestoreEventFiresOnlyOnRecalledEvents(): void
    {
        $runId = 'durable_restore_seam_test';
        $persistence = new InMemoryPersistence();

        // Run 1: every node executes live — restore must never fire, so a live
        // result's transient capability (e.g. a middleware-shaped tool set) is
        // never touched by the seam.
        $workflow = RestoreSpyWorkflow::make(address: $runId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        $state = $this->execute($workflow, $persistence);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame([], $workflow->restored);

        // Resume in a fresh instance: exactly the deserialized events are
        // restored — the adopted ignition start event and node A's recalled
        // result. Nodes B and C run live and never pass through the seam.
        $workflow2 = RestoreSpyWorkflow::make(address: $runId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->resume($workflow2, $persistence, ['approve_b' => 'approve']);

        $this->assertFalse($result->isInterrupted());
        $this->assertSame([StartEvent::class, DurableEventA::class], $workflow2->restored);
    }

    public function testCrashRecordsFailedStepMarker(): void
    {
        $runId = 'durable_failed_marker_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(address: $runId)
            ->addNodes([new MemoizingNode(shouldCrash: true)]);

        try {
            $this->execute($workflow, $persistence);
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        // The crashed step leaves a failed marker, making the run observable.
        $raw = $persistence->get($runId, $workflow->getRunId() . '/' . MemoizingNode::class . '-0');
        $this->assertNotNull($raw);
        $failed = (new PhpSerializer())->unserialize($raw);
        $this->assertInstanceOf(StepResult::class, $failed);
        $this->assertTrue($failed->isFailed());
        $this->assertStringContainsString('Simulated crash', $failed->getError()['message']);
    }
}
