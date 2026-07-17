<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\CheckpointableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MultiCheckpointTextProcessNode;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class CheckpointParallelTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testCheckpointValueSavedBeforeInterruptInBranch(): void
    {
        $executor = $this->createExecutor();

        $checkpointNode = new CheckpointableTextProcessNode();
        $workflow = Workflow::make(resumeToken: 'test-checkpoint-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                $checkpointNode,
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow, $executor);

        $this->assertTrue($state->isInterrupted());
        // The durable-memoized operation executed exactly once before the branch paused.
        $this->assertSame(1, $checkpointNode->closureExecutions);
    }

    public function testCheckpointNotReExecutedOnParallelResume(): void
    {
        $executor = $this->createExecutor();

        $checkpointNode = new CheckpointableTextProcessNode();
        $workflow = Workflow::make(resumeToken: 'test-checkpoint-resume')
            ->addNodes([
                new InterruptableBranchProcessing(),
                $checkpointNode,
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow, $executor);
        $this->assertTrue($state->isInterrupted());
        $this->assertSame(1, $checkpointNode->closureExecutions);

        $result = $this->execute($workflow, $executor, $state->getInterrupt());

        $this->assertFalse($result->isInterrupted());
        // Memo hit on resume: the node re-executes but the memoized closure does NOT.
        $this->assertSame(1, $checkpointNode->closureExecutions);
        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testCheckpointWithCompletedBranchRetainedAcrossInterrupt(): void
    {
        $executor = $this->createExecutor();

        $checkpointNode = new CheckpointableTextProcessNode();
        $workflow = Workflow::make(resumeToken: 'test-checkpoint-order')
            ->addNodes([
                new ImageFirstForkNode(),
                $checkpointNode,
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow, $executor);
        $this->assertTrue($state->isInterrupted());

        // The already-completed image branch is retained across the pause and
        // contributes its result on resume.
        $result = $this->execute($workflow, $executor, $state->getInterrupt());

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testMultipleCheckpointsInParallelBranch(): void
    {
        $executor = $this->createExecutor();

        $workflow = Workflow::make(resumeToken: 'test-multi-checkpoint')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new MultiCheckpointTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow, $executor);
        $this->assertTrue($state->isInterrupted());

        $result = $this->execute($workflow, $executor, $state->getInterrupt());

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $textResult = $analysis['text'];
        $this->assertSame('MULTI_CHECKPOINT_APPROVED', $textResult['status']);
        $this->assertSame('step1_done', $textResult['cp1']);
        $this->assertSame('step2_done', $textResult['cp2']);
    }
}
