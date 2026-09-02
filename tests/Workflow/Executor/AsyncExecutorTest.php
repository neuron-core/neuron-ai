<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use Generator;
use NeuronAI\Tests\Support\ExecutorTestHelpers;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
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
use NeuronAI\Workflow\Executor\AsyncExecutor;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

use function Amp\async;
use function Amp\delay;
use function microtime;

class AsyncExecutorTest extends TestCase
{
    use ExecutorTestHelpers;

    protected function executor(): AsyncExecutor
    {
        return new AsyncExecutor();
    }

    public function test_async_executor_with_normal_nodes(): void
    {
        $workflow = Workflow::make()
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

    public function test_parallel_branches_run_with_default_executor(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),
                new SlowImageProcessNode(),
                new MergeNode(),
            ]);

        // Deliberately bypass the class's async executor override: this test
        // proves the DEFAULT executor runs branches one by one.
        $start = microtime(true);
        $workflow->run();
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThan(0.15, $elapsed, 'The default executor should run branches one by one');
    }

    public function test_async_executor_runs_branches_concurrently(): void
    {
        $workflow = Workflow::make()
            ->addNodes([
                new DocumentParallelProcessing(),
                new SlowTextProcessNode(),
                new SlowImageProcessNode(),
                new MergeNode(),
            ]);


        $start = microtime(true);
        $this->execute($workflow);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(0.18, $elapsed, 'AsyncExecutor should run branches concurrently');
    }

    public function test_branch_state_is_isolated_and_merged(): void
    {
        $workflow = Workflow::make()
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

    public function test_same_node_context_is_isolated_between_parallel_branches(): void
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
            {
                return new DocumentParallelEvent([
                    'text' => new TextProcessEvent(),
                    'image' => new TextProcessEvent(),
                ]);
            }
        };

        $sharedNode = new class () extends Node {
            public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
            {
                delay(0.01);

                return new StopEvent(result: $this->state->get('__branchId'));
            }
        };

        $workflow = Workflow::make()
            ->addNodes([$fork, $sharedNode, new MergeNode()]);

        $result = $this->execute($workflow);

        $this->assertSame([
            'text' => 'text',
            'image' => 'image',
        ], $result->get('analysis'));
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

        $workflow = Workflow::make()->addNodes([
            new DocumentParallelProcessing(),
            $streamingNode,
            new ImageProcessNode(),
            new MergeNode(),
        ]);
        $this->configure($workflow);

        $payloads = [];
        foreach ($workflow->events() as $event) {
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
        $this->assertTrue($workflow->getState()->get('merge_node_executed'));
    }
}
