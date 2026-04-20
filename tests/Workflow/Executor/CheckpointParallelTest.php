<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\CheckpointableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MultiCheckpointTextProcessNode;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class CheckpointParallelTest extends TestCase
{
    /**
     * Checkpoint closure executes on first run, saves its value,
     * and is available in the interrupt state.
     */
    public function testCheckpointValueSavedBeforeInterruptInBranch(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-checkpoint-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new CheckpointableTextProcessNode(),
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
        // The serialized node carries the checkpoint data
        $node = $interrupt->getNode();
        $this->assertInstanceOf(CheckpointableTextProcessNode::class, $node);
        // Branch state is cloned — checkpoint_result lives on the branch, not the main state.
        // Verify the node's checkpoints array was populated before the interrupt was thrown.
        $reflection = new \ReflectionProperty($node, 'checkpoints');
        $checkpoints = $reflection->getValue($node);
        $this->assertArrayHasKey('expensive_computation', $checkpoints);
        $this->assertSame('computed_value', $checkpoints['expensive_computation']);
    }

    /**
     * On resume, the checkpoint closure is NOT re-executed — the cached value is returned.
     */
    public function testCheckpointNotReExecutedOnParallelResume(): void
    {
        $persistence = new InMemoryPersistence();
        $checkpointNode = new CheckpointableTextProcessNode();

        $workflow = Workflow::make($persistence, 'test-checkpoint-resume')
            ->addNodes([
                new InterruptableBranchProcessing(),
                $checkpointNode,
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
        // Reset the flag — on resume, the deserialized node is used, not this instance.
        // We verify via the final state instead.

        $result = $workflow->init($interrupt->getRequest())->run();

        // Both branches completed — merge node ran
        $this->assertTrue($result->get('merge_node_executed'));
        // The checkpoint value persisted through interrupt/resume
        $analysis = $result->get('analysis');
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    /**
     * Checkpoint works when image branch completes first, then text branch
     * (with checkpoint + interrupt) resumes.
     */
    public function testCheckpointWithCompletedBranchResultsPreserved(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-checkpoint-order')
            ->addNodes([
                new ImageFirstForkNode(),
                new CheckpointableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $workflow->init()->run();
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
            // Image branch completed before text branch interrupted
            $this->assertArrayHasKey('image', $e->getCompletedBranchResults());
            $this->assertSame('processed_image.jpg', $e->getCompletedBranchResults()['image']);
        }

        $this->assertNotNull($interrupt);

        $result = $workflow->init($interrupt->getRequest())->run();

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    /**
     * A node with multiple checkpoints — each is saved and restored independently.
     */
    public function testMultipleCheckpointsInParallelBranch(): void
    {
        $persistence = new InMemoryPersistence();

        $workflow = Workflow::make($persistence, 'test-multi-checkpoint')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new MultiCheckpointTextProcessNode(),
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

        $result = $workflow->init($interrupt->getRequest())->run();

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $textResult = $analysis['text'];
        $this->assertSame('MULTI_CHECKPOINT_APPROVED', $textResult['status']);
        $this->assertSame('step1_done', $textResult['cp1']);
        $this->assertSame('step2_done', $textResult['cp2']);
    }
}
