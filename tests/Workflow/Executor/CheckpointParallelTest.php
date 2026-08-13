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
        $checkpointNode = new CheckpointableTextProcessNode();
        $workflow = Workflow::make(workflowId: 'test-checkpoint-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                $checkpointNode,
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);

        $this->assertTrue($state->isInterrupted());
        // The durable-memoized operation executed exactly once before the branch paused.
        $this->assertSame(1, $checkpointNode->closureExecutions);
    }

    public function testCheckpointNotReExecutedOnParallelResume(): void
    {
        $checkpointNode = new CheckpointableTextProcessNode();
        $workflow = Workflow::make(workflowId: 'test-checkpoint-resume')
            ->addNodes([
                new InterruptableBranchProcessing(),
                $checkpointNode,
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);
        $this->assertTrue($state->isInterrupted());
        $this->assertSame(1, $checkpointNode->closureExecutions);

        $result = $this->resume($workflow);

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
        $checkpointNode = new CheckpointableTextProcessNode();
        $workflow = Workflow::make(workflowId: 'test-checkpoint-order')
            ->addNodes([
                new ImageFirstForkNode(),
                $checkpointNode,
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);
        $this->assertTrue($state->isInterrupted());

        // The already-completed image branch is retained across the pause and
        // contributes its result on resume.
        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testMultipleCheckpointsInParallelBranch(): void
    {
        $workflow = Workflow::make(workflowId: 'test-multi-checkpoint')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new MultiCheckpointTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $state = $this->execute($workflow);
        $this->assertTrue($state->isInterrupted());

        $result = $this->resume($workflow);

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $textResult = $analysis['text'];
        $this->assertSame('MULTI_CHECKPOINT_APPROVED', $textResult['status']);
        $this->assertSame('step1_done', $textResult['cp1']);
        $this->assertSame('step2_done', $textResult['cp2']);
    }
}
