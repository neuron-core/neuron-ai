<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use DateTimeImmutable;
use Generator;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\ConcurrentWaitNode;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\SequentialBranchRunner;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use PHPUnit\Framework\TestCase;
use stdClass;

use function Amp\delay;
use function array_map;
use function iterator_to_array;
use function serialize;

class SequentialInterruptionTest extends TestCase
{
    protected function workflow(InMemoryPersistence $persistence, stdClass $trace, bool $repeat = false, ?DateTimeImmutable $expiresAt = null): Workflow
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
            {
                return new DocumentParallelEvent(['b' => new TextProcessEvent(), 'a' => new TextProcessEvent()]);
            }
        };
        $join = new class () extends Node {
            public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('results', $event->getAllResults());
                return new StopEvent();
            }
        };
        return Workflow::make('sequential-interruptions')->setPersistence($persistence)
            ->setBranchRunner(new AsyncBranchRunner())->addNodes([$fork, new ConcurrentWaitNode($trace, $repeat, $expiresAt), $join]);
    }

    protected function request(WorkflowState $state): WaitForEventRequest
    {
        $request = $state->getInterruptRequest();
        $this->assertInstanceOf(WaitForEventRequest::class, $request);
        return $request;
    }

    public function test_concurrent_waits_are_exposed_in_arrival_order_across_fresh_executors(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];
        $first = $this->workflow($persistence, $trace)->run();
        $this->assertSame('a', $this->request($first)->getEventName());
        $this->assertSame(['b.started', 'a.started', 'a.waiting', 'b.waiting'], $trace->events);
        $second = $this->workflow($persistence, $trace)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['value' => 'A']));
        $this->assertSame('b', $this->request($second)->getEventName());
        $this->assertSame(['b.started', 'a.started', 'a.waiting', 'b.waiting', 'a.finished'], $trace->events);
        $last = $this->workflow($persistence, $trace)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['value' => 'B']));
        $this->assertFalse($last->isInterrupted());
        $this->assertSame(['value' => 'A'], $last->get('results')['a']);
        $this->assertSame(['value' => 'B'], $last->get('results')['b']);
    }

    public function test_an_existing_deferred_request_precedes_a_new_request_from_the_resumed_node(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];
        $this->workflow($persistence, $trace, repeat: true)->run();
        $second = $this->workflow($persistence, $trace, repeat: true)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['value' => 'A']));
        $this->assertSame('b', $this->request($second)->getEventName());
        $third = $this->workflow($persistence, $trace, repeat: true)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume(['value' => 'B']));
        $this->assertSame('a.again', $this->request($third)->getEventName());
        $last = $this->workflow($persistence, $trace, repeat: true)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));
        $this->assertFalse($last->isInterrupted());
        $this->assertSame(['value' => 'A'], $last->get('results')['a']);
    }

    public function test_deferred_expiration_keeps_its_deadline_and_waits_for_the_current_request(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];
        $expiresAt = new DateTimeImmutable('-1 minute');
        $this->workflow($persistence, $trace, expiresAt: $expiresAt)->run();
        $waiting = $this->workflow($persistence, $trace, expiresAt: $expiresAt)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $this->assertSame('a', $this->request($waiting)->getEventName());
        $second = $this->workflow($persistence, $trace, expiresAt: $expiresAt)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));
        $this->assertSame('b', $this->request($second)->getEventName());
        $this->assertEquals($expiresAt, $this->request($second)->getExpiresAt());
        $last = $this->workflow($persistence, $trace, expiresAt: $expiresAt)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume());
        $this->assertFalse($last->isInterrupted());
        $this->assertNull($last->get('results')['b']);
    }

    public function test_a_signal_cannot_answer_a_deferred_request(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];
        $this->workflow($persistence, $trace)->run();
        $before = serialize($persistence);
        try {
            $this->workflow($persistence, $trace)->run(\NeuronAI\Workflow\Executor\ExecutionRequest::signal('b', []));
            $this->fail('A deferred request must wait its turn.');
        } catch (WorkflowException) {
            $this->assertSame($before, serialize($persistence));
        }
    }

    public function test_running_stream_finishes_its_node_and_replays_without_starting_the_next_node(): void
    {
        $trace = (object) ['events' => []];
        $persistence = new InMemoryPersistence();
        $stream = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): Generator
            {
                $this->trace->events[] = 'started';
                yield new ChunkEvent('first');
                delay(0.01);
                $this->memoize('work', function (): bool {
                    $this->trace->events[] = 'memoized';
                    return true;
                });
                yield new ChunkEvent('last');
                $this->trace->events[] = 'finished';
                return new ImageProcessEvent();
            }
        };
        $wait = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
            {
                if ($this->branchId === 'image') {
                    $this->awaitEvent('approval');
                } else {
                    $this->trace->events[] = 'next';
                }
                return new StopEvent('done');
            }
        };
        $make = fn (): Workflow => Workflow::make('drain-stream')->setPersistence($persistence)
            ->setBranchRunner(new AsyncBranchRunner())->addNodes([new DocumentParallelProcessing(), $stream, $wait, new MergeNode()]);
        $events = iterator_to_array($make()->events());
        $this->assertSame([ChunkEvent::class, ChunkEvent::class, InterruptEvent::class], array_map(fn (Event $event): string => $event::class, $events));
        $this->assertSame(['started', 'memoized', 'finished'], $trace->events);
        $state = $make()->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]));
        $this->assertFalse($state->isInterrupted());
        $this->assertSame(['started', 'memoized', 'finished', 'next'], $trace->events);
        $this->assertSame(['text' => 'done', 'image' => 'done'], $state->get('analysis'));
    }

    public function test_the_sequential_executor_starts_only_one_branch_before_suspending(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];
        $workflow = $this->workflow($persistence, $trace)->setBranchRunner(new SequentialBranchRunner());
        $this->assertSame('b', $this->request($workflow->run())->getEventName());
        $this->assertSame(['b.started', 'b.waiting'], $trace->events);
        $this->assertSame('a', $this->request($workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([])))->getEventName());
        $this->assertSame(['b.started', 'b.waiting', 'b.finished', 'a.started', 'a.waiting'], $trace->events);
        $this->assertFalse($workflow->run(\NeuronAI\Workflow\Executor\ExecutionRequest::resume([]))->isInterrupted());
    }
}
