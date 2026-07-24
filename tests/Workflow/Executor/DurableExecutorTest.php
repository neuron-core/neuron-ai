<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\CountableNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableInterruptNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeA;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeC;
use NeuronAI\Tests\Workflow\Executor\Stubs\MemoizingNode;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
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

    protected function createDurableExecutor(?PersistenceInterface $persistence = null): WorkflowExecutor
    {
        return new WorkflowExecutor(
            new LocalStepEngine($persistence ?? new InMemoryPersistence()),
        );
    }

    public function testMemoizationOnCrashRecovery(): void
    {
        $workflowId = 'durable_crash_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crush when run
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $this->createDurableExecutor($persistence));
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        // Node A executed (completed), node B executed and crashed, node C never ran
        $this->assertSame(2, CountableNode::getExecutionCount());

        // Recovery run — same workflowId, node B won't crash
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->execute($workflow2, $this->createDurableExecutor($persistence));

        // Node A should be memoized (skipped), nodes B and C execute fresh
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($result->get('step_a_executed'));
        $this->assertTrue($result->get('step_b_executed'));
        $this->assertTrue($result->get('step_c_executed'));
    }

    public function testInterruptThenResumeMemoizesCompletedSteps(): void
    {
        $workflowId = 'durable_interrupt_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        // First run — pauses at node B
        $state = $this->execute(
            $workflow,
            $this->createDurableExecutor($persistence),
        );

        $this->assertTrue($state->isInterrupted());
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($state->get('step_a_executed'));
        $this->assertTrue($state->get('step_b_executed'));
        // Paused steps are retained for resume (cleanup only runs on completion)
        $this->assertNotNull($persistence->load($workflowId, DurableNodeA::class . '-0'));

        // Resume — deliver the payload (node B just checks isResuming()).
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->resume(
            $workflow2,
            $this->createDurableExecutor($persistence),
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
        $workflowId = 'durable_cleanup_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $this->execute($workflow, $this->createDurableExecutor($persistence));

        // Steps should be deleted after successful completion
        $this->assertNull($persistence->load($workflowId, DurableNodeA::class . '-0'));
        $this->assertNull($persistence->load($workflowId, DurableNodeB::class . '-1'));
        $this->assertNull($persistence->load($workflowId, DurableNodeC::class . '-2'));
    }

    public function testStepsNotCleanedUpAfterCrash(): void
    {
        $workflowId = 'durable_crash_cleanup_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crush when run
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $this->createDurableExecutor($persistence));
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException) {
            // After crash, completed steps should still be persisted
            $this->assertNotNull($persistence->load($workflowId, DurableNodeA::class . '-0'));
        }
    }

    public function testDefaultInMemoryPersistence(): void
    {
        $executor = new WorkflowExecutor(new LocalStepEngine(new InMemoryPersistence()));
        $workflow = Workflow::make()
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = $this->execute($workflow, $executor);

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function testMemoizationWithFreshExecutor(): void
    {
        $workflowId = 'durable_fresh_engine_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crash
                new DurableNodeC(),
            ]);

        // Run 1: Node A completes, node B crashes
        try {
            $this->execute($workflow, $this->createDurableExecutor($persistence));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        $this->assertSame(2, CountableNode::getExecutionCount());

        // Recovery with a fresh executor + same persistence (simulates process restart)
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->execute($workflow2, $this->createDurableExecutor($persistence));

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

        $workflowId = 'durable_memoize_test';
        $persistence = new InMemoryPersistence();

        // Run 1: the memoized operation runs, its value is persisted mid-node,
        // then the node crashes before returning.
        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([new MemoizingNode(shouldCrash: true)]);

        try {
            $this->execute($workflow, $this->createDurableExecutor($persistence));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash after memoize', $e->getMessage());
        }

        // The expensive operation ran exactly once, even though the node crashed.
        $this->assertSame(1, MemoizingNode::getOperationCount());

        // Recovery with a fresh executor + same persistence (simulates process restart).
        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(resumeToken: $workflowId)
            ->addNodes([new MemoizingNode(shouldCrash: false)]);

        $result = $this->execute($workflow2, $this->createDurableExecutor($persistence));

        // The node re-executed (it never completed before), but the memoized operation
        // did NOT run again — it was replayed from the persisted memo.
        $this->assertSame(1, CountableNode::getExecutionCount());
        $this->assertSame(1, MemoizingNode::getOperationCount());
        $this->assertSame('computed_1', $result->get('memo_result'));
    }

    public function testCrashRecordsFailedStepMarker(): void
    {
        $workflowId = 'durable_failed_marker_test';
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([new MemoizingNode(shouldCrash: true)]);

        try {
            $this->execute($workflow, $this->createDurableExecutor($persistence));
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated crash', $e->getMessage());
        }

        // The crashed step leaves a failed marker, making the run observable.
        $failed = $persistence->load($workflowId, MemoizingNode::class . '-0');
        $this->assertNotNull($failed);
        $this->assertTrue($failed->isFailed());
        $this->assertStringContainsString('Simulated crash', $failed->getError()['message']);
    }
}
