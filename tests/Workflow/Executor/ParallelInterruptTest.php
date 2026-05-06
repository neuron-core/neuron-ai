<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\ContinuationNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableStep1TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableStep2TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\LinearInterruptNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeWithContinuationNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\SummaryProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ThreeBranchImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ThreeBranchMergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ThreeBranchProcessing;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class ParallelInterruptTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testInterruptInsideBranchThrowsWorkflowInterrupt(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $caught = false;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $caught = true;
            $this->assertTrue($e->isParallelInterrupt());
            $this->assertSame('text', $e->getBranchId());
        }

        $this->assertTrue($caught, 'Expected WorkflowInterrupt to be thrown');
    }

    public function testParallelInterruptCapturesMainState(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $this->assertFalse($e->getState()->has('__branchId'));
            $this->assertNotNull($e->getParallelEvent());
            $this->assertInstanceOf(DocumentParallelEvent::class, $e->getParallelEvent());
        }
    }

    public function testParallelInterruptPreservesCompletedBranchResults(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new ThreeBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new SummaryProcessNode(),
                new ThreeBranchMergeNode(),
            ]);

        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $this->assertArrayNotHasKey('text', $e->getCompletedBranchResults());
        }
    }

    public function testCompletedResultsPreservedWhenBranchRunsBeforeInterrupt(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new ImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $completed = $e->getCompletedBranchResults();
            $this->assertArrayHasKey('image', $completed);
            $this->assertSame('processed_image.jpg', $completed['image']);
            $this->assertArrayNotHasKey('text', $completed);
        }
    }

    public function testParallelResumeCompletesAllBranches(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        $result = $this->execute($workflow, $executor, $interrupt->getRequest());

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testParallelResumeContinuesPastJoinNode(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeWithContinuationNode(),
                new ContinuationNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        $result = $this->execute($workflow, $executor, $interrupt->getRequest());

        $this->assertTrue($result->get('merge_node_executed'));
        $this->assertTrue($result->get('continuation_node_executed'));
    }

    public function testParallelResumeWithThreeBranches(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new ThreeBranchImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new SummaryProcessNode(),
                new ThreeBranchMergeNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
            $this->assertArrayHasKey('image', $e->getCompletedBranchResults());
        }

        $this->assertNotNull($interrupt);

        $result = $this->execute($workflow, $executor, $interrupt->getRequest());

        $this->assertTrue($result->get('merge_node_executed'));
        $mergeResults = $result->get('merge_results');
        $this->assertSame('TEXT_APPROVED', $mergeResults['text']);
        $this->assertSame('processed_image.jpg', $mergeResults['image']);
        $this->assertSame('SUMMARY', $mergeResults['summary']);
    }

    public function testReInterruptInResumedBranch(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableStep1TextProcessNode(),
                new InterruptableStep2TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt1 = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt1 = $e;
        }
        $this->assertNotNull($interrupt1);
        $this->assertTrue($interrupt1->isParallelInterrupt());
        $this->assertSame('step1 approval', $interrupt1->getMessage());

        $interrupt2 = null;
        try {
            $this->execute($workflow, $executor, $interrupt1->getRequest());
        } catch (WorkflowInterrupt $e) {
            $interrupt2 = $e;
        }
        $this->assertNotNull($interrupt2);
        $this->assertTrue($interrupt2->isParallelInterrupt());
        $this->assertSame('step2 approval', $interrupt2->getMessage());

        $result = $this->execute($workflow, $executor, $interrupt2->getRequest());
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TWO_STEP_APPROVED', $analysis['text']);
    }

    public function testLinearInterruptNotAffected(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-linear-token')
            ->addNodes([new LinearInterruptNode()]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);
        $this->assertFalse($interrupt->isParallelInterrupt());
        $this->assertNull($interrupt->getBranchId());
        $this->assertNull($interrupt->getParallelEvent());
        $this->assertSame([], $interrupt->getCompletedBranchResults());
    }

    public function testAsyncParallelInterruptCapturesParallelContext(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = new AsyncExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-async-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);
        $this->assertTrue($interrupt->isParallelInterrupt());
        $this->assertSame('text', $interrupt->getBranchId());
    }

    public function testAsyncParallelResumeCompletesAllBranches(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = new AsyncExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-async-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        $result = $this->execute($workflow, $executor, $interrupt->getRequest());

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testParallelInterruptSerializationRoundTrip(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-serialize-token')
            ->addNodes([
                new ImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        $loaded = $persistence->load('test-serialize-token');

        $this->assertTrue($loaded->isParallelInterrupt());
        $this->assertSame('text', $loaded->getBranchId());
        $this->assertInstanceOf(DocumentParallelEvent::class, $loaded->getParallelEvent());
        $this->assertSame('text branch needs approval', $loaded->getMessage());

        $branches = $loaded->getParallelEvent()->branches;
        $this->assertArrayHasKey('text', $branches);
        $this->assertArrayHasKey('image', $branches);
    }
}
