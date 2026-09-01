<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\CheckpointableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MultiCheckpointTextProcessNode;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class CheckpointParallelTest extends TestCase
{
    use ExecutorTestHelpers;

    public function test_checkpoint_value_saved_before_interrupt_in_branch(): void
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

    public function test_checkpoint_not_re_executed_on_parallel_resume(): void
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

    public function test_checkpoint_with_completed_branch_retained_across_interrupt(): void
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

    public function test_multiple_checkpoints_in_parallel_branch(): void
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
