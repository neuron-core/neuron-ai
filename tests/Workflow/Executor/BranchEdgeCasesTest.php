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

use function array_filter;
use function in_array;
use function reset;

class BranchEdgeCasesTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function executor(): AsyncExecutor
    {
        return new AsyncExecutor();
    }

    public function test_multi_step_branch_executes_all_nodes(): void
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

    public function test_streaming_node_inside_branch_completes_successfully(): void
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

    public function test_streamed_nodes_in_both_branches_complete(): void
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

    public function test_async_multi_step_branch_completes_all_nodes(): void
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

    public function test_middleware_fires_inside_branches(): void
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

    public function test_async_middleware_carries_branch_id(): void
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

    public function test_async_observer_receives_all_events(): void
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

        [$result, $events] = $this->executeAndCollect($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
        $this->assertNotEmpty($observer->recorded);
    }
}
