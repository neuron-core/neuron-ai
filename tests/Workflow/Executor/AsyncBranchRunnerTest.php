<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\SlowImageProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\SlowTextProcessNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

use function Amp\async;
use function iterator_to_array;
use function microtime;

class AsyncBranchRunnerTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function branchRunner(): AsyncBranchRunner
    {
        return new AsyncBranchRunner();
    }

    public function test_async_runner_with_normal_nodes(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new NodeOne(),
                new NodeTwo(),
                new NodeThree(),
            ]);

        $result = async(fn (): \NeuronAI\Workflow\WorkflowState => $this->execute($workflow))->await();

        $this->assertTrue($result->get('node_one_executed'));
        $this->assertTrue($result->get('node_two_executed'));
        $this->assertTrue($result->get('node_three_executed'));
    }

    public function test_parallel_branches_run_with_the_default_runner(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),
                new SlowImageProcessNode(),
                new MergeNode(),
            ]);

        // Deliberately bypass the class's branch runner override: this test
        // proves the DEFAULT runner runs branches one by one.
        $start = microtime(true);
        $workflow->run();
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(0.15, $elapsed, 'The default runner should run branches one by one');
    }

    public function test_async_runner_runs_branches_concurrently(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),
                new SlowImageProcessNode(),
                new MergeNode(),
            ]);


        $start = microtime(true);
        $this->execute($workflow);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.18, $elapsed, 'AsyncBranchRunner should run branches concurrently');
    }

    public function test_branch_state_is_isolated_and_merged(): void
    {
        $workflow = Workflow::make('test-workflow')
            ->addNodes([
                new DocumentParallelProcessing(),
                new TextProcessNode(),
                new ImageProcessNode(),
                new MergeNode(),
            ]);

        $result = $this->execute($workflow);

        $analysis = $result->get('analysis');
        $this->assertSame('HELLO', $analysis['text']);
        $this->assertSame('processed_image.jpg', $analysis['image']);
    }

    public function test_parallel_streaming_is_live_and_backpressured(): void
    {
        $progress = new stdClass();
        $progress->advancedPastFirstEvent = false;

        $streamingNode = new class ($progress) extends Node {
            public function __construct(protected stdClass $progress)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): Generator
            {
                yield new ChunkEvent('first');
                $this->progress->advancedPastFirstEvent = true;
                yield new ChunkEvent('second');

                return new StopEvent(result: 'HELLO');
            }
        };

        $workflow = Workflow::make('test-workflow')->addNodes([
            new DocumentParallelProcessing(),
            $streamingNode,
            new ImageProcessNode(),
            new MergeNode(),
        ]);
        $this->configure($workflow);

        $payloads = [];
        $stream = $workflow->events();
        foreach ($stream as $event) {
            if (!$event instanceof ChunkEvent) {
                continue;
            }

            $payloads[] = $event->payload;
            if ($event->payload === 'first') {
                $this->assertFalse($progress->advancedPastFirstEvent);
            }
        }

        $this->assertSame(['first', 'second'], $payloads);
        $this->assertTrue($progress->advancedPastFirstEvent);
        $this->assertTrue($stream->getReturn()->get('merge_node_executed'));
    }

    public function test_branches_stream_any_object_in_the_sequence_of_the_segment(): void
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): Generator
            {
                yield new ChunkEvent('fork');

                return new DocumentParallelEvent([
                    'text' => new TextProcessEvent(),
                    'image' => new ImageProcessEvent(),
                ]);
            }
        };
        $text = new class () extends Node {
            public function __invoke(TextProcessEvent $event, WorkflowState $state): Generator
            {
                yield new TextChunk('msg_1', 'Hello');

                return new StopEvent(result: 'HELLO');
            }
        };
        $workflow = Workflow::make('test-workflow')->addNodes([$fork, $text, new ImageProcessNode(), new MergeNode()]);
        $this->configure($workflow);

        // Keyed by position: the branch continues the sequence the fork started.
        $items = iterator_to_array($workflow->events());

        $this->assertCount(2, $items);
        $this->assertInstanceOf(ChunkEvent::class, $items[0]);
        $this->assertInstanceOf(TextChunk::class, $items[1]);
    }
}
