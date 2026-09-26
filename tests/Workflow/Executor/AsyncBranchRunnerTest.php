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
use NeuronAI\Tests\Workflow\Executor\Stub\SummaryProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessNode;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\NodeTwo;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function Amp\async;
use function Amp\delay;
use function array_keys;
use function iterator_to_array;

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

    /**
     * A text branch and an image branch that record when their node starts
     * and ends; the image branch runs a second node afterwards.
     *
     * @return list<Node>
     */
    protected function tracedBranches(stdClass $trace): array
    {
        $text = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'text.start';
                delay(0.001);
                if ($this->trace->failText) {
                    throw new RuntimeException('text failed');
                }
                $this->trace->events[] = 'text.end';

                return new StopEvent('text');
            }
        };
        $image = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(ImageProcessEvent $event, WorkflowState $state): SummaryProcessEvent
            {
                $this->trace->events[] = 'image.start';
                delay(0.005);
                if ($this->trace->failImage) {
                    $this->trace->events[] = 'image.failed';
                    throw new RuntimeException('image failed');
                }
                $this->trace->events[] = 'image.end';

                return new SummaryProcessEvent();
            }
        };
        $imageNext = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(SummaryProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'image.next';

                return new StopEvent('image');
            }
        };

        return [new DocumentParallelProcessing(), $text, $image, $imageNext, new MergeNode()];
    }

    protected function trace(bool $failText = false, bool $failImage = false): stdClass
    {
        return (object) ['events' => [], 'failText' => $failText, 'failImage' => $failImage];
    }

    public function test_the_default_runner_finishes_a_branch_before_starting_the_next(): void
    {
        $trace = $this->trace();

        $state = Workflow::make('sequential-branches')->addNodes($this->tracedBranches($trace))->run();

        $this->assertSame(['text.start', 'text.end', 'image.start', 'image.end', 'image.next'], $trace->events);
        $this->assertSame(['text' => 'text', 'image' => 'image'], $state->get('analysis'));
    }

    public function test_async_runner_runs_branches_concurrently(): void
    {
        $trace = $this->trace();

        $state = $this->execute(Workflow::make('async-branches')->addNodes($this->tracedBranches($trace)));

        $this->assertSame(['text.start', 'image.start', 'text.end', 'image.end', 'image.next'], $trace->events);
        $this->assertSame(['text' => 'text', 'image' => 'image'], $state->get('analysis'));
    }

    public function test_a_failing_branch_lets_a_running_sibling_finish_its_node_but_start_no_other(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = $this->trace(failText: true);

        try {
            $this->execute(Workflow::make('async-failure')->addNodes($this->tracedBranches($trace)), $persistence);
            $this->fail('Expected the text branch to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('text failed', $e->getMessage());
        }
        $this->assertSame(['text.start', 'image.start', 'image.end'], $trace->events);

        // The drained node committed its step: recovery does not run it again.
        $trace->failText = false;
        $trace->events = [];
        $state = $this->resume(Workflow::make('async-failure')->addNodes($this->tracedBranches($trace)), $persistence, null);

        $this->assertEqualsCanonicalizing(['text.start', 'text.end', 'image.next'], $trace->events);
        $this->assertSame(['text' => 'text', 'image' => 'image'], $state->get('analysis'));
    }

    public function test_the_first_failure_wins_after_every_branch_is_drained(): void
    {
        $trace = $this->trace(failText: true, failImage: true);

        try {
            $this->execute(Workflow::make('async-failures')->addNodes($this->tracedBranches($trace)));
            $this->fail('Expected both branches to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('text failed', $e->getMessage());
        }

        $this->assertSame(['text.start', 'image.start', 'image.failed'], $trace->events);
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

        $this->assertSame([0, 1], array_keys($items));
        $this->assertInstanceOf(ChunkEvent::class, $items[0]);
        $this->assertSame('fork', $items[0]->payload);
        $this->assertInstanceOf(TextChunk::class, $items[1]);
    }
}
