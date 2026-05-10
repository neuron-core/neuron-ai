<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\CountableNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableInterruptNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeA;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeB;
use NeuronAI\Tests\Workflow\Executor\Stubs\DurableNodeC;
use NeuronAI\Tests\Workflow\Stubs\NodeOne;
use NeuronAI\Tests\Workflow\Stubs\NodeThree;
use NeuronAI\Tests\Workflow\Stubs\NodeTwo;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
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

    protected function createDurableExecutor(?LocalStepEngine $stepEngine = null): WorkflowExecutor
    {
        return new WorkflowExecutor(
            $stepEngine ?? new LocalStepEngine(),
        );
    }

    public function testMemoizationOnCrashRecovery(): void
    {
        $workflowId = 'durable_crash_test';
        $stepEngine = new LocalStepEngine();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crush when run
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $this->createDurableExecutor($stepEngine));
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

        $result = $this->execute($workflow2, $this->createDurableExecutor($stepEngine));

        // Node A should be memoized (skipped), nodes B and C execute fresh
        $this->assertSame(2, CountableNode::getExecutionCount());
        $this->assertTrue($result->get('step_a_executed'));
        $this->assertTrue($result->get('step_b_executed'));
        $this->assertTrue($result->get('step_c_executed'));
    }

    public function testInterruptThenResumeMemoizesCompletedSteps(): void
    {
        $workflowId = 'durable_interrupt_test';
        $stepEngine = new LocalStepEngine();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        // First run — interrupts at node B
        try {
            $this->execute(
                $workflow,
                $this->createDurableExecutor($stepEngine),
            );
            $this->fail('Expected WorkflowInterrupt was not thrown');
        } catch (WorkflowInterrupt) {
            $this->assertSame(2, CountableNode::getExecutionCount());
            $this->assertTrue($workflow->resolveState()->get('step_a_executed'));
            $this->assertTrue($workflow->resolveState()->get('step_b_executed'));
        }

        // Resume — approve the action
        $request = new ApprovalRequest('resume');
        $request->getAction('approve_b')?->approve();

        CountableNode::resetExecutionCount();
        $workflow2 = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableInterruptNodeB(),
                new DurableNodeC(),
            ]);

        $result = $this->execute(
            $workflow2,
            $this->createDurableExecutor($stepEngine),
            $request,
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
        $stepEngine = new LocalStepEngine();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(),
                new DurableNodeC(),
            ]);

        $this->execute($workflow, $this->createDurableExecutor($stepEngine));

        // Steps should be deleted after successful completion
        $this->assertNull($stepEngine->getStep(DurableNodeA::class . '-0'));
        $this->assertNull($stepEngine->getStep(DurableNodeB::class . '-1'));
        $this->assertNull($stepEngine->getStep(DurableNodeC::class . '-2'));
    }

    public function testStepsNotCleanedUpAfterCrash(): void
    {
        $workflowId = 'durable_crash_cleanup_test';
        $stepEngine = new LocalStepEngine();

        $workflow = Workflow::make(resumeToken: $workflowId)
            ->addNodes([
                new DurableNodeA(),
                new DurableNodeB(true), // crush when run
                new DurableNodeC(),
            ]);

        try {
            $this->execute($workflow, $this->createDurableExecutor($stepEngine));
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException) {
            // After crash, completed steps should still be in the engine
            $this->assertNotNull($stepEngine->getStep(DurableNodeA::class . '-0'));
        }
    }

    public function testBackwardCompatWithoutStepEngine(): void
    {
        $executor = new WorkflowExecutor();
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
}
