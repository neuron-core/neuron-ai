<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\CheckpointableTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageFirstForkNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\InterruptableBranchProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MultiCheckpointTextProcessNode;
use NeuronAI\Workflow\Interrupt\WorkflowInterrupt;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class CheckpointParallelTest extends TestCase
{
    use ExecutorTestHelpers;

    public function testCheckpointValueSavedBeforeInterruptInBranch(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-checkpoint-token')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new CheckpointableTextProcessNode(),
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

        $node = $interrupt->getNode();
        $this->assertInstanceOf(CheckpointableTextProcessNode::class, $node);

        $reflection = new ReflectionProperty($node, 'checkpoints');
        $checkpoints = $reflection->getValue($node);
        $this->assertArrayHasKey('expensive_computation', $checkpoints);
        $this->assertSame('computed_value', $checkpoints['expensive_computation']);
    }

    public function testCheckpointNotReExecutedOnParallelResume(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-checkpoint-resume')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new CheckpointableTextProcessNode(),
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
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testCheckpointWithCompletedBranchResultsPreserved(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-checkpoint-order')
            ->addNodes([
                new ImageFirstForkNode(),
                new CheckpointableTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $interrupt = null;
        try {
            $this->execute($workflow, $executor);
        } catch (WorkflowInterrupt $e) {
            $interrupt = $e;
            $this->assertArrayHasKey('image', $e->getCompletedBranchResults());
            $this->assertSame('processed_image.jpg', $e->getCompletedBranchResults()['image']);
        }

        $this->assertNotNull($interrupt);

        $result = $this->execute($workflow, $executor, $interrupt->getRequest());

        $this->assertTrue($result->get('merge_node_executed'));
        $analysis = $result->get('analysis');
        $this->assertSame('CHECKPOINT_APPROVED', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testMultipleCheckpointsInParallelBranch(): void
    {
        $persistence = new InMemoryPersistence();
        $executor = $this->createExecutor($persistence);

        $workflow = Workflow::make(resumeToken: 'test-multi-checkpoint')
            ->addNodes([
                new InterruptableBranchProcessing(),
                new MultiCheckpointTextProcessNode(),
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
        $textResult = $analysis['text'];
        $this->assertSame('MULTI_CHECKPOINT_APPROVED', $textResult['status']);
        $this->assertSame('step1_done', $textResult['cp1']);
        $this->assertSame('step2_done', $textResult['cp2']);
    }
}
