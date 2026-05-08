<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\TextProcessNode;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Executor\WorkflowExecutor;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class DurableBranchTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function createDurableExecutor(?LocalStepEngine $stepEngine = null): WorkflowExecutor
    {
        return new WorkflowExecutor(
            $stepEngine ?? new LocalStepEngine(),
        );
    }

    public function testParallelBranchWithStepEngineCompletesAllBranches(): void
    {
        $stepEngine = new LocalStepEngine();

        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow, $this->createDurableExecutor($stepEngine));

        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testMainFlowWithStepEngine(): void
    {
        $stepEngine = new LocalStepEngine();

        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow, $this->createDurableExecutor($stepEngine));

        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }
}
