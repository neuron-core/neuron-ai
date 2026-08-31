<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\ContinuationNode;
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
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class ParallelInterruptTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testInterruptInsideBranchSurfacesRequest(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);

        // The branch's pause surfaces on the workflow state, and the join node
        // (which runs only after all branches complete) did not execute.
        $this->assertTrue($state->isInterrupted());
        $this->assertSame('text branch needs approval', $state->getInterruptRequest()->getMessage());
        $this->assertFalse($state->has('merge_node_executed'));
    }

    public function testParallelResumeCompletesAllBranches(): void
    {
        // Durability: resume on a fresh executor sharing only persistence.
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $request = $this->execute($workflow, $persistence)->getInterruptRequest();
        $this->assertNotNull($request);

        $resumed = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->resume($resumed, $persistence, []);

        $this->assertFalse($result->isInterrupted());
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testCompletedBranchRetainedAcrossInterrupt(): void
    {
        // Image branch completes, then the text branch pauses. On resume the
        // already-completed image branch must still contribute its result —
        // proving completed branches are retained, not lost, across the pause.
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new ImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testParallelResumeContinuesPastJoinNode(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeWithContinuationNode(),
                new ContinuationNode(),
            ]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $this->assertTrue($result->get('continuation_node_executed'));
    }

    public function testParallelResumeWithThreeBranches(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new ThreeBranchImageFirstForkNode(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new SummaryProcessNode(),
                new ThreeBranchMergeNode(),
            ]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $mergeResults = $result->get('merge_results');
        $this->assertSame('TEXT_APPROVED', $mergeResults['text']);
        $this->assertSame('processed_image.jpg', $mergeResults['image']);
        $this->assertSame('SUMMARY', $mergeResults['summary']);
    }

    public function testReInterruptInResumedBranch(): void
    {
        $workflow = Workflow::make(workflowId: 'test-resume-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableStep1TextProcessNode(),
                new InterruptableStep2TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        // First pause: step1 approval
        $request1 = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request1);
        $this->assertSame('step1 approval', $request1->getMessage());

        // Resuming re-enters the branch and pauses again at step2
        $request2 = $this->resume($workflow)->getInterruptRequest();
        $this->assertNotNull($request2);
        $this->assertSame('step2 approval', $request2->getMessage());

        $result = $this->resume($workflow);
        $this->assertFalse($result->isInterrupted());
        $this->assertTrue($result->get('merge_node_executed'));
        $this->assertSame('TWO_STEP_APPROVED', $result->get('analysis')['text']);
    }

    public function testLinearInterruptSurfacesAndResumes(): void
    {
        // A non-parallel interrupt behaves the same way: surfaces on the state,
        // resumes through the request. (No parallel-specific metadata exists.)
        $workflow = Workflow::make(workflowId: 'test-linear-token')
            ->addNodes([new LinearInterruptNode()]);

        $request = $this->execute($workflow)->getInterruptRequest();
        $this->assertNotNull($request);
        $this->assertSame('linear interrupt', $request->getMessage());

        $result = $this->resume($workflow);
        $this->assertFalse($result->isInterrupted());
    }

    public function testAsyncParallelInterruptSurfacesRequest(): void
    {
        $workflow = Workflow::make(workflowId: 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);

        $this->assertTrue($state->isInterrupted());
        $this->assertSame('text branch needs approval', $state->getInterruptRequest()->getMessage());
    }

    public function testAsyncParallelResumeCompletesAllBranches(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make(workflowId: 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $request = $this->execute($workflow, $persistence)->getInterruptRequest();
        $this->assertNotNull($request);

        $resumed = Workflow::make(workflowId: 'test-async-token')
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new InterruptableBranchProcessing(),
                new InterruptableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->resume($resumed, $persistence, []);

        $this->assertFalse($result->isInterrupted());
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('TEXT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }
}
