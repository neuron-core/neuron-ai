<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Executor\Stub\SummaryProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ThreeBranchParallelEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use stdClass;

use function Amp\delay;
use function array_column;
use function array_filter;

/**
 * A branch may fork again: the text branch opens an inner fork whose single
 * leaf branch runs the summary node.
 */
class NestedForkTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected stdClass $trace;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
        $this->trace = (object) ['events' => []];
    }

    protected function workflow(bool $leafWaits, bool $imageWaits, bool $async): Workflow
    {
        $text = new class ($this->trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): ThreeBranchParallelEvent
            {
                $this->trace->events[] = 'text';
                delay(0.005);

                return new ThreeBranchParallelEvent(['leaf' => new SummaryProcessEvent()]);
            }
        };
        $leaf = new class ($this->trace, $leafWaits) extends Node {
            public function __construct(protected stdClass $trace, protected bool $waits)
            {
            }

            public function __invoke(SummaryProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'leaf';

                return new StopEvent($this->waits ? $this->awaitEvent('leaf') : 'leaf');
            }
        };
        $innerJoin = new class () extends Node {
            public function __invoke(ThreeBranchParallelEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent(['inner' => $event->getAllResults()]);
            }
        };
        $image = new class ($this->trace, $imageWaits) extends Node {
            public function __construct(protected stdClass $trace, protected bool $waits)
            {
            }

            public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'image';

                return new StopEvent($this->waits ? $this->awaitEvent('image') : 'image');
            }
        };

        $workflow = Workflow::make('nested-fork')->setPersistence($this->persistence)
            ->addNodes([new DocumentParallelProcessing(), $text, $leaf, $innerJoin, $image, new MergeNode()]);
        if ($async) {
            $workflow->setBranchRunner(new AsyncBranchRunner());
        }

        return $workflow;
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_an_interruption_inside_a_nested_fork_resumes_only_its_leaf(bool $async): void
    {
        $suspended = $this->workflow(leafWaits: true, imageWaits: false, async: $async)->run();
        $this->assertSame('leaf', $this->eventName($suspended->getInterruptRequest()));

        $completed = $this->workflow(leafWaits: true, imageWaits: false, async: $async)
            ->run(ExecutionRequest::resume(['answer' => 42]));

        $this->assertSame(
            ['text' => ['inner' => ['leaf' => ['answer' => 42]]], 'image' => 'image'],
            $completed->get('analysis'),
        );
        $this->assertEqualsCanonicalizing(['text', 'leaf', 'leaf', 'image'], $this->trace->events);
    }

    public function test_a_sibling_interruption_stops_a_branch_before_it_opens_its_nested_fork(): void
    {
        $observer = new RecordingObserver();
        $suspended = $this->workflow(leafWaits: false, imageWaits: true, async: true)->observe($observer)->run();

        $this->assertSame('image', $this->eventName($suspended->getInterruptRequest()));
        $this->assertEqualsCanonicalizing(['text', 'image'], $this->trace->events);
        $this->assertEqualsCanonicalizing(['text', 'image'], array_column(array_filter(
            $observer->recorded,
            fn (array $record): bool => $record['event'] === 'branch-start',
        ), 'branchId'));

        $completed = $this->workflow(leafWaits: false, imageWaits: true, async: true)
            ->run(ExecutionRequest::resume(['answer' => 'image']));

        // The text node is replayed from its committed step; only the leaf is new.
        $this->assertSame(['text', 'image', 'image', 'leaf'], $this->trace->events);
        $this->assertSame(
            ['text' => ['inner' => ['leaf' => 'leaf']], 'image' => ['answer' => 'image']],
            $completed->get('analysis'),
        );
    }

    protected function eventName(?InterruptRequest $request): string
    {
        $this->assertInstanceOf(WaitForEventRequest::class, $request);

        return $request->getEventName();
    }
}
