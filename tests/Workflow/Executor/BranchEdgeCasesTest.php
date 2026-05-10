<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Observability\EventBus;
use NeuronAI\Tests\Workflow\Executor\Stubs\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stubs\FinalTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\MultiStepTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\RecordingMiddleware;
use NeuronAI\Tests\Workflow\Executor\Stubs\RecordingObserver;
use NeuronAI\Tests\Workflow\Executor\Stubs\StreamingImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stubs\StreamingTextProcessNode;
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Executor\LocalStepEngine;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function in_array;
use function reset;

class BranchEdgeCasesTest extends TestCase
{
    use ExecutorTestHelpers;

    private function createAsyncExecutor(): AsyncExecutor
    {
        return new AsyncExecutor(new LocalStepEngine(new InMemoryPersistence()));
    }

    protected function tearDown(): void
    {
        EventBus::clear();
    }

    public function testMultiStepBranchExecutesAllNodes(): void
    {
        $workflow = Workflow::make()
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

    public function testStreamingNodeInsideBranchCompletesSuccessfully(): void
    {
        $workflow = Workflow::make()
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

    public function testStreamedNodesInBothBranchesComplete(): void
    {
        $workflow = Workflow::make()
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

    public function testAsyncMultiStepBranchCompletesAllNodes(): void
    {
        $executor = $this->createAsyncExecutor();

        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow, $executor);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function testMiddlewareFiresInsideBranches(): void
    {
        $middleware = new RecordingMiddleware();

        $workflow = Workflow::make()
            ->addGlobalMiddleware($middleware)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $this->execute($workflow);

        $this->assertCount(6, $middleware->beforeCalls);
        $this->assertCount(6, $middleware->afterCalls);

        $byBranch = fn (array $calls, string $nodeClass): array => array_filter(
            $calls,
            fn (array $c): bool => $c['node'] === $nodeClass,
        );

        $forkBefore = $byBranch($middleware->beforeCalls, DocumentParallelProcessing::class);
        $this->assertCount(1, $forkBefore);
        $this->assertNull(reset($forkBefore)['branchId']);

        $step1Before = $byBranch($middleware->beforeCalls, MultiStepTextProcessNode::class);
        $this->assertCount(1, $step1Before);
        $this->assertSame('text', reset($step1Before)['branchId']);

        $streamBefore = $byBranch($middleware->beforeCalls, StreamingTextProcessNode::class);
        $this->assertCount(1, $streamBefore);
        $this->assertSame('text', reset($streamBefore)['branchId']);

        $finalBefore = $byBranch($middleware->beforeCalls, FinalTextProcessNode::class);
        $this->assertCount(1, $finalBefore);
        $this->assertSame('text', reset($finalBefore)['branchId']);

        $imageBefore = $byBranch($middleware->beforeCalls, ImageProcessNode::class);
        $this->assertCount(1, $imageBefore);
        $this->assertSame('image', reset($imageBefore)['branchId']);

        $mergeBefore = $byBranch($middleware->beforeCalls, MergeNode::class);
        $this->assertCount(1, $mergeBefore);
        $this->assertNull(reset($mergeBefore)['branchId']);
    }

    public function testAsyncMiddlewareCarriesBranchId(): void
    {
        $executor = $this->createAsyncExecutor();
        $middleware = new RecordingMiddleware();

        $workflow = Workflow::make()
            ->addGlobalMiddleware($middleware)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $this->execute($workflow, $executor);

        $this->assertCount(6, $middleware->beforeCalls);
        $this->assertCount(6, $middleware->afterCalls);

        $textNodes = [MultiStepTextProcessNode::class, StreamingTextProcessNode::class, FinalTextProcessNode::class];
        foreach ($middleware->beforeCalls as $call) {
            if (in_array($call['node'], $textNodes, true)) {
                $this->assertSame('text', $call['branchId'], "Expected branchId='text' for {$call['node']}");
            }
        }

        $imageCalls = array_filter($middleware->beforeCalls, fn (array $c): bool => $c['node'] === ImageProcessNode::class);
        $this->assertCount(1, $imageCalls);
        $this->assertSame('image', reset($imageCalls)['branchId']);
    }

    public function testAsyncEventBusReceivesAllEvents(): void
    {
        $observer = new RecordingObserver();

        $workflow = Workflow::make()
            ->observe($observer)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $executor = $this->createAsyncExecutor();
        [$result, $events] = $this->executeAndCollect($workflow, $executor);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }
}
