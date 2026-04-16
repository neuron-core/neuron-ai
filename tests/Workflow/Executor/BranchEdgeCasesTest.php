<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Observability\EventBus;
use NeuronAI\Tests\Workflow\Executor\Stubs\ChunkEvent;
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
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function in_array;
use function reset;

class BranchEdgeCasesTest extends TestCase
{
    protected function tearDown(): void
    {
        EventBus::clear();
    }

    /**
     * A branch with 3 chained nodes (A→B→C→StopEvent) executes all steps
     * and produces the correct final result via the merge node.
     */
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

        $result = $workflow->init()->run();

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    /**
     * A streaming node inside a branch yields chunk events that are collected
     * into the branch's streamedEvents and yielded to the consumer after
     * the branch completes.
     */
    public function testStreamingNodeInsideBranchCollectsAllChunkEvents(): void
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

        $events = [];
        foreach ($workflow->init()->events() as $event) {
            $events[] = $event;
        }

        $chunks = array_filter($events, fn (mixed $e): bool => $e instanceof ChunkEvent);
        $payloads = array_map(fn (ChunkEvent $e): string => $e->payload, $chunks);

        $this->assertCount(2, $chunks);
        $this->assertSame(['text-1', 'text-2'], $payloads);
    }

    /**
     * When both branches contain streaming nodes, their chunk events are
     * batched per branch: all events from one branch appear contiguously,
     * then all events from the other. Sequential executor runs branches
     * in array order ('text' before 'image').
     */
    public function testStreamedEventsAreBatchedPerBranch(): void
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

        $events = [];
        foreach ($workflow->init()->events() as $event) {
            $events[] = $event;
        }

        $payloads = array_map(
            fn (ChunkEvent $e): string => $e->payload,
            array_filter($events, fn (mixed $e): bool => $e instanceof ChunkEvent),
        );

        // All text chunks, then all image chunks (sequential executor)
        $this->assertSame(['text-1', 'text-2', 'image-1', 'image-2'], $payloads);
    }

    /**
     * AsyncExecutor runs a 3-node branch with a streaming middle node
     * and produces correct results.
     */
    public function testAsyncMultiStepBranchWithStreaming(): void
    {
        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $events = [];
        $result = null;
        foreach ($workflow->init()->events() as $event) {
            $events[] = $event;
        }
        // Re-run to get the final state (events() returns it as generator return)
        $result = $workflow->init()->run();

        $analysis = $result->get('analysis');
        $this->assertSame('MULTI_STEP_COMPLETE', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);

        $chunks = array_filter($events, fn (mixed $e): bool => $e instanceof ChunkEvent);
        $this->assertCount(2, $chunks);
    }

    /**
     * Middleware before() and after() fire for every node inside parallel
     * branches, including multi-step chains. The middleware receives the
     * cloned branch state which carries the correct __branchId.
     */
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

        $workflow->init()->run();

        // 6 nodes: fork, 3 text steps, image, merge
        $this->assertCount(6, $middleware->beforeCalls);
        $this->assertCount(6, $middleware->afterCalls);

        // Verify branchId is correct per node
        $byBranch = fn (array $calls, string $nodeClass): array => array_filter(
            $calls,
            fn (array $c): bool => $c['node'] === $nodeClass,
        );

        // Fork node — main loop, no branchId
        $forkBefore = $byBranch($middleware->beforeCalls, DocumentParallelProcessing::class);
        $this->assertCount(1, $forkBefore);
        $this->assertNull(reset($forkBefore)['branchId']);

        // Text step 1 — branch 'text'
        $step1Before = $byBranch($middleware->beforeCalls, MultiStepTextProcessNode::class);
        $this->assertCount(1, $step1Before);
        $this->assertSame('text', reset($step1Before)['branchId']);

        // Streaming step — branch 'text'
        $streamBefore = $byBranch($middleware->beforeCalls, StreamingTextProcessNode::class);
        $this->assertCount(1, $streamBefore);
        $this->assertSame('text', reset($streamBefore)['branchId']);

        // Final step — branch 'text'
        $finalBefore = $byBranch($middleware->beforeCalls, FinalTextProcessNode::class);
        $this->assertCount(1, $finalBefore);
        $this->assertSame('text', reset($finalBefore)['branchId']);

        // Image node — branch 'image'
        $imageBefore = $byBranch($middleware->beforeCalls, ImageProcessNode::class);
        $this->assertCount(1, $imageBefore);
        $this->assertSame('image', reset($imageBefore)['branchId']);

        // Merge node — main loop, no branchId
        $mergeBefore = $byBranch($middleware->beforeCalls, MergeNode::class);
        $this->assertCount(1, $mergeBefore);
        $this->assertNull(reset($mergeBefore)['branchId']);
    }

    /**
     * With AsyncExecutor, middleware still fires inside concurrent fibers
     * and sees the correct branchId for each branch.
     */
    public function testAsyncMiddlewareCarriesBranchId(): void
    {
        $middleware = new RecordingMiddleware();

        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->addGlobalMiddleware($middleware)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $workflow->init()->run();

        // Same node count as sequential
        $this->assertCount(6, $middleware->beforeCalls);
        $this->assertCount(6, $middleware->afterCalls);

        // Every text branch node has branchId='text'
        $textNodes = [MultiStepTextProcessNode::class, StreamingTextProcessNode::class, FinalTextProcessNode::class];
        foreach ($middleware->beforeCalls as $call) {
            if (in_array($call['node'], $textNodes, true)) {
                $this->assertSame('text', $call['branchId'], "Expected branchId='text' for {$call['node']}");
            }
        }

        // Image node has branchId='image'
        $imageCalls = array_filter($middleware->beforeCalls, fn (array $c): bool => $c['node'] === ImageProcessNode::class);
        $this->assertCount(1, $imageCalls);
        $this->assertSame('image', reset($imageCalls)['branchId']);
    }

    /**
     * The static EventBus handles concurrent emissions from async branches
     * without losing events. An observer registered on the workflow receives
     * all node start/end events for every branch.
     */
    public function testAsyncEventBusReceivesAllEvents(): void
    {
        $observer = new RecordingObserver();

        $workflow = Workflow::make()
            ->setExecutor(new AsyncExecutor())
            ->observe($observer)
            ->addNodes([
                new DocumentParallelProcessing(),
                new MultiStepTextProcessNode(),
                new StreamingTextProcessNode(),
                new FinalTextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $workflow->init()->run();

        $nodeStarts = array_filter($observer->recorded, fn (array $r): bool => $r['event'] === 'workflow-node-start');
        $nodeEnds = array_filter($observer->recorded, fn (array $r): bool => $r['event'] === 'workflow-node-end');

        // 6 nodes: fork, 3 text steps, image, merge
        $this->assertCount(6, $nodeStarts, 'Every node should emit a workflow-node-start event');
        $this->assertCount(6, $nodeEnds, 'Every node should emit a workflow-node-end event');

        // Node start events in branches carry the correct branchId
        $branchStarts = array_filter($nodeStarts, fn (array $r): bool => $r['branchId'] !== null);
        $this->assertCount(4, $branchStarts, '4 branch nodes (3 text + 1 image) should have branchId');

        $textStarts = array_filter($branchStarts, fn (array $r): bool => $r['branchId'] === 'text');
        $imageStarts = array_filter($branchStarts, fn (array $r): bool => $r['branchId'] === 'image');
        $this->assertCount(3, $textStarts, 'Text branch has 3 nodes');
        $this->assertCount(1, $imageStarts, 'Image branch has 1 node');
    }
}
