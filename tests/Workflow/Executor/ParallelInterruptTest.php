<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableStep1TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableStep2TextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ContinuationNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableBranchProcessing;
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
    /**
     * An interrupt inside a parallel branch throws WorkflowInterrupt
     * (not some other exception type).
     */
    public function testInterruptInsideBranchThrowsWorkflowInterrupt(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $caught = false;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $caught = true;
            $this->assertTrue($e->isParallelInterrupt());
            $this->assertSame('text', $e->getBranchId());
        }

        $this->assertTrue($caught, 'Expected WorkflowInterrupt to be thrown');
    }

    /**
     * The interrupt captures the main workflow state, not the branch's cloned state.
     */
    public function testParallelInterruptCapturesMainState(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            // The main state should NOT have __branchId set
            $this->assertFalse($e->getState()->has('__branchId'));
            $this->assertNotNull($e->getParallelEvent());
            $this->assertInstanceOf(DocumentParallelEvent::class, $e->getParallelEvent());
        }
    }

    /**
     * When one branch interrupts, previously completed branches' results
     * are preserved in the interrupt's completedBranchResults.
     */
    public function testParallelInterruptPreservesCompletedBranchResults(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new ThreeBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new SummaryProcessNode(),
                new ThreeBranchMergeNode(),
            ]);

        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            // ThreeBranchProcessing returns: text, image, summary
            // 'text' runs first and interrupts — no branches complete before it
            $this->assertArrayNotHasKey('text', $e->getCompletedBranchResults());
        }
    }

    /**
     * Test that completed results are preserved when image branch runs before text branch.
     */
    public function testCompletedResultsPreservedWhenBranchRunsBeforeInterrupt(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new ImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $completed = $e->getCompletedBranchResults();
            // 'image' runs first and completes successfully
            $this->assertArrayHasKey('image', $completed);
            $this->assertSame('processed_image.jpg', $completed['image']);
            // 'text' interrupted — not in completed results
            $this->assertArrayNotHasKey('text', $completed);
        }
    }

    /**
     * Resume: the interrupted branch continues, remaining branches run,
     * and the join node receives all results.
     */
    public function testParallelResumeCompletesAllBranches(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        // Resume the workflow
        $result = $workflow->init($interrupt->getRequest())->run();

        // Both branches should have completed
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    /**
     * Resume: workflow continues past the join node to subsequent nodes.
     */
    public function testParallelResumeContinuesPastJoinNode(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeWithContinuationNode(),
                new ContinuationNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        $result = $workflow->init($interrupt->getRequest())->run();

        // Join node executed
        $this->assertTrue($result->get('merge_node_executed'));
        // Continuation node after join also executed
        $this->assertTrue($result->get('continuation_node_executed'));
    }

    /**
     * Resume with 3 branches: interrupted branch resumes,
     * completed branches use cached results, remaining branches run.
     */
    public function testParallelResumeWithThreeBranches(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new ThreeBranchImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new SummaryProcessNode(),
                new ThreeBranchMergeNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
            // Image completed before text interrupted
            $this->assertArrayHasKey('image', $e->getCompletedBranchResults());
        }

        $this->assertNotNull($interrupt);

        $result = $workflow->init($interrupt->getRequest())->run();

        $this->assertTrue($result->get('merge_node_executed'));
        $mergeResults = $result->get('merge_results');
        $this->assertSame('TEXT_APPROVED', $mergeResults['text']);
        $this->assertSame('processed_image.jpg', $mergeResults['image']);
        $this->assertSame('SUMMARY', $mergeResults['summary']);
    }

    /**
     * Re-interrupt: branch has two nodes, each with an interrupt.
     * First node interrupts, resumes, then second node interrupts.
     */
    public function testReInterruptInResumedBranch(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableStep1TextProcessNode(),
                new InterruptableStep2TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        // First run — step 1 interrupts
        $interrupt1 = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt1 = $e;
        }
        $this->assertNotNull($interrupt1);
        $this->assertTrue($interrupt1->isParallelInterrupt());
        $this->assertSame('step1 approval', $interrupt1->getMessage());

        // Resume — passes step 1, step 2 interrupts
        $interrupt2 = null;
        try {
            $workflow->init($interrupt1->getRequest())->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt2 = $e;
        }
        $this->assertNotNull($interrupt2);
        $this->assertTrue($interrupt2->isParallelInterrupt());
        $this->assertSame('step2 approval', $interrupt2->getMessage());

        // Resume again — passes step 2, branch completes, merge runs
        $result = $workflow->init($interrupt2->getRequest())->run();
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TWO_STEP_APPROVED', $analysis['text']);
    }

    /**
     * Linear interrupts are not affected by the parallel context changes.
     */
    public function testLinearInterruptNotAffected(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-linear-token')
            ->addNodes([new LinearInterruptNode()]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);
        $this->assertFalse($interrupt->isParallelInterrupt());
        $this->assertNull($interrupt->getBranchId());
        $this->assertNull($interrupt->getParallelEvent());
        $this->assertSame([], $interrupt->getCompletedBranchResults());
    }

    /**
     * Async executor: interrupt inside a branch with concurrent execution.
     */
    public function testAsyncParallelInterruptCapturesParallelContext(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);
        $this->assertTrue($interrupt->isParallelInterrupt());
        $this->assertSame('text', $interrupt->getBranchId());
    }

    /**
     * Async executor: resume after branch interrupt.
     */
    public function testAsyncParallelResumeCompletesAllBranches(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        // Resume uses the sequential path (inherited from WorkflowExecutor)
        $result = $workflow->init($interrupt->getRequest())->run();

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    /**
     * The interrupt persists and loads correctly through serialization.
     */
    public function testParallelInterruptSerializationRoundTrip(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-serialize-token')
            ->addNodes([
                new ImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
        }

        $this->assertNotNull($interrupt);

        // Reload from persistence
        $loaded = $persistence->load('test-serialize-token');

        $this->assertTrue($loaded->isParallelInterrupt());
        $this->assertSame('text', $loaded->getBranchId());
        $this->assertInstanceOf(DocumentParallelEvent::class, $loaded->getParallelEvent());
        $this->assertSame('text branch needs approval', $loaded->getMessage());

        // Check the parallel event has the branches
        $branches = $loaded->getParallelEvent()->branches;
        $this->assertArrayHasKey('text', $branches);
        $this->assertArrayHasKey('image', $branches);
    }
}
