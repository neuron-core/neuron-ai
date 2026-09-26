<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use DateTimeImmutable;
use Generator;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\RecordingObserver;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelProcessing;
use NeuronAI\Tests\Workflow\Executor\Stub\SummaryProcessEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
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
use function array_column;
use function array_count_values;
use function array_filter;
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
        } catch (WorkflowException $e) {
            $this->assertSame("The current interruption is not waiting for signal 'b'.", $e->getMessage());
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

    public function test_waits_are_exposed_in_arrival_order_not_declaration_order(): void
    {
        $persistence = new InMemoryPersistence();
        $make = fn (): Workflow => $this->arrivalWorkflow($persistence);

        $exposed = [$this->request($make()->run())->getEventName()];
        foreach (['fast', 'mid'] as $answered) {
            $exposed[] = $this->request($make()->run(ExecutionRequest::resume(['from' => $answered])))->getEventName();
        }
        $completed = $make()->run(ExecutionRequest::resume(['from' => 'slow']));

        $this->assertSame(['fast', 'mid', 'slow'], $exposed);
        $this->assertSame(
            ['slow' => ['from' => 'slow'], 'fast' => ['from' => 'fast'], 'mid' => ['from' => 'mid']],
            $completed->get('results'),
        );
    }

    /**
     * The text branch waits for a reply; the image branch finishes its first
     * node meanwhile and still has a second one to run.
     */
    protected function replyFirstWorkflow(InMemoryPersistence $persistence, stdClass $trace): Workflow
    {
        $waiting = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
            {
                if (!$this->isResuming()) {
                    delay(0.001);
                }
                $answer = $this->awaitEvent('text');
                // Yield to other branches while the answer is being handled.
                delay(0.002);
                $this->trace->events[] = 'text.answered';

                return new StopEvent($answer);
            }
        };
        $slow = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(ImageProcessEvent $event, WorkflowState $state): SummaryProcessEvent
            {
                delay(0.005);
                $this->trace->events[] = 'image.first';

                return new SummaryProcessEvent();
            }
        };
        $next = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(SummaryProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'image.second';

                return new StopEvent('image');
            }
        };

        return Workflow::make('reply-first')->setPersistence($persistence)
            ->setBranchRunner(new AsyncBranchRunner())
            ->addNodes([new DocumentParallelProcessing(), $waiting, $slow, $next, new MergeNode()]);
    }

    public function test_an_accepted_reply_reaches_its_node_before_other_branches_start_new_nodes(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];

        $this->assertSame('text', $this->request($this->replyFirstWorkflow($persistence, $trace)->run())->getEventName());
        $this->assertSame(['image.first'], $trace->events);

        $completed = $this->replyFirstWorkflow($persistence, $trace)->run(ExecutionRequest::resume(['ok' => true]));

        $this->assertSame(['image.first', 'text.answered', 'image.second'], $trace->events);
        $this->assertSame(['text' => ['ok' => true], 'image' => 'image'], $completed->get('analysis'));
    }

    public function test_a_branch_completed_by_the_reply_is_not_reentered_when_deferred_branches_run_again(): void
    {
        $persistence = new InMemoryPersistence();
        $trace = (object) ['events' => []];
        $this->replyFirstWorkflow($persistence, $trace)->run();
        $observer = new RecordingObserver();

        $this->replyFirstWorkflow($persistence, $trace)->observe($observer)->run(ExecutionRequest::resume(['ok' => true]));

        $branchStarts = array_column(array_filter(
            $observer->recorded,
            fn (array $record): bool => $record['event'] === 'branch-start',
        ), 'branchId');
        $this->assertSame(1, array_count_values($branchStarts)['text']);
        $this->assertContains('image', $branchStarts);
    }

    /**
     * Three concurrent branches, declared slow-first, that wait after
     * different delays. Each branch has its own node: concurrent branches
     * must not share one.
     */
    protected function arrivalWorkflow(InMemoryPersistence $persistence): Workflow
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
            {
                return new DocumentParallelEvent([
                    'slow' => new TextProcessEvent(),
                    'fast' => new ImageProcessEvent(),
                    'mid' => new SummaryProcessEvent(),
                ]);
            }
        };
        $slow = new class () extends Node {
            public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
            {
                if (!$this->isResuming()) {
                    delay(0.009);
                }
                return new StopEvent($this->awaitEvent('slow'));
            }
        };
        $fast = new class () extends Node {
            public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
            {
                if (!$this->isResuming()) {
                    delay(0.001);
                }
                return new StopEvent($this->awaitEvent('fast'));
            }
        };
        $mid = new class () extends Node {
            public function __invoke(SummaryProcessEvent $event, WorkflowState $state): StopEvent
            {
                if (!$this->isResuming()) {
                    delay(0.005);
                }
                return new StopEvent($this->awaitEvent('mid'));
            }
        };
        $join = new class () extends Node {
            public function __invoke(DocumentParallelEvent $event, WorkflowState $state): StopEvent
            {
                $state->set('results', $event->getAllResults());
                return new StopEvent();
            }
        };

        return Workflow::make('arrival-order')->setPersistence($persistence)
            ->setBranchRunner(new AsyncBranchRunner())->addNodes([$fork, $slow, $fast, $mid, $join]);
    }
}
