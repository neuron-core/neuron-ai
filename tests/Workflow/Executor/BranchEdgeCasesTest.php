<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\FinalTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MultiStepTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingMiddleware;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Executor\Stub\StreamingImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\StreamingTextProcessNode;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Workflow;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_values;
use function count;

class BranchEdgeCasesTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function branchRunner(): AsyncBranchRunner
    {
        return new AsyncBranchRunner();
    }

    public function test_multi_step_branch_executes_all_nodes_and_streams_their_output(): void
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

        [$result, $events] = $this->executeAndCollect($workflow);

        $this->assertSame(['text-1', 'text-2'], array_map(fn (ChunkEvent $chunk): string => $chunk->payload, $events));
        $this->assertSame(['text' => 'MULTI_STEP_COMPLETE', 'image' => 'processed_image.jpg'], $result->get('analysis'));
        // Branch nodes write to the branch's own state, never to the merged one.
        $this->assertFalse($result->has('multi_step1_executed'));
        $this->assertFalse($result->has('streaming_step_executed'));
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

        [$result, $events] = $this->executeAndCollect($workflow);

        $this->assertEqualsCanonicalizing(
            ['text-1', 'text-2', 'image-1', 'image-2'],
            array_map(fn (ChunkEvent $chunk): string => $chunk->payload, $events),
        );
        $this->assertSame(['text' => 'MULTI_STEP_COMPLETE', 'image' => 'streamed_image'], $result->get('analysis'));
    }

    public function test_middleware_fires_inside_branches(): void
    {
        $middleware = new RecordingMiddleware();

        $workflow = Workflow::make('test-workflow')
            ->addGlobalMiddleware(fn (): \NeuronAI\Tests\Workflow\Executor\Stub\RecordingMiddleware => $middleware)
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

    public function test_async_observer_receives_branch_events_with_their_branch(): void
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

        $this->execute($workflow);

        $branchEvents = array_values(array_filter(
            $observer->recorded,
            fn (array $record): bool => $record['event'] === 'branch-start' || $record['event'] === 'branch-end',
        ));
        $this->assertEqualsCanonicalizing([
            ['event' => 'branch-start', 'branchId' => 'text'],
            ['event' => 'branch-start', 'branchId' => 'image'],
            ['event' => 'branch-end', 'branchId' => 'text'],
            ['event' => 'branch-end', 'branchId' => 'image'],
        ], $branchEvents);
        $nodeStarts = array_values(array_filter(
            $observer->recorded,
            fn (array $record): bool => $record['event'] === 'workflow-node-start',
        ));
        $this->assertEqualsCanonicalizing(
            ['__main__', 'text', 'text', 'text', 'image', '__main__'],
            array_column($nodeStarts, 'branchId'),
        );
        $this->assertSame('workflow-start', $observer->recorded[0]['event']);
        $this->assertSame('workflow-end', $observer->recorded[count($observer->recorded) - 1]['event']);
    }
}
