<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\FinalTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MultiStepTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingMiddleware;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Executor\Stub\StreamingImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\StreamingTextProcessNode;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

class BranchEdgeCasesTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function executor(): AsyncExecutor
    {
        return new AsyncExecutor();
    }

    public function test_multi_step_branch_executes_all_nodes(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_streaming_node_inside_branch_completes_successfully(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_streamed_nodes_in_both_branches_complete(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new StreamingImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('streamed_image', $analysis['image']);
    }

    public function test_async_multi_step_branch_completes_all_nodes(): void
    {

        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_middleware_fires_inside_branches(): void
    {
        $middleware = new RecordingMiddleware();

        $workflow = Workflow::make('test-workflow')
            ->addGlobalMiddleware(fn () => $middleware)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $this->execute($workflow);

        $nodes = [
            DocumentParallelProcessing::class,
            MultiStepTextProcessNode::class,
            StreamingTextProcessNode::class,
            FinalTextProcessNode::class,
            ImageProcessNode::class,
            MergeNode::class,
        ];
        $this->assertEqualsCanonicalizing($nodes, $middleware->beforeCalls);
        $this->assertEqualsCanonicalizing($nodes, $middleware->afterCalls);
    }

    public function test_async_observer_receives_all_events(): void
    {
        $observer = new RecordingObserver();

        $workflow = Workflow::make('test-workflow')
            ->observe($observer)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        [$result, $events] = $this->executeAndCollect($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
        $this->assertNotEmpty($observer->recorded);
    }
}
