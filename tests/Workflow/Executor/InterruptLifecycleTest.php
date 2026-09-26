<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Executor;

use DateTimeImmutable;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\ClaimBeforeCleanupPersistence;
use NeuronAI\Tests\Workflow\Executor\Stub\CrashAfterClaim;
use NeuronAI\Tests\Workflow\Executor\Stub\DocumentParallelEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\ImageProcessEvent;
use NeuronAI\Tests\Workflow\Executor\Stub\MergeNode;
use NeuronAI\Tests\Workflow\Executor\Stub\TextProcessEvent;
use NeuronAI\Tests\Workflow\Stub\NodeOne;
use NeuronAI\Tests\Workflow\Stub\NodeThree;
use NeuronAI\Tests\Workflow\Stub\SleepUntilNode;
use NeuronAI\Tests\Workflow\Stub\WaitForEventNode;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\AsyncBranchRunner;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function Amp\delay;
use function serialize;

/**
 * How a segment turns node interruptions into the run's single current
 * request, and how continuations may answer it.
 */
class InterruptLifecycleTest extends TestCase
{
    protected InMemoryPersistence $persistence;

    protected function setUp(): void
    {
        $this->persistence = new InMemoryPersistence();
    }

    protected function twoWaits(): Workflow
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                // A replayed node re-enters from the top: the first answer is memoized.
                $state->set('first', $this->memoize('first', fn (): ?array => $this->awaitEvent('first')));
                $state->set('second', $this->awaitEvent('second'));

                return new StopEvent();
            }
        };

        return Workflow::make('two-waits')->setPersistence($this->persistence)->addNode($node);
    }

    protected function signalWorkflow(): Workflow
    {
        return Workflow::make('signals')->setPersistence($this->persistence)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
    }

    public function test_interrupt_ids_increase_within_a_run_and_restart_for_the_next_run(): void
    {
        $first = $this->twoWaits()->run();
        $second = $this->twoWaits()->run(ExecutionRequest::resume(['n' => 1]));
        $completed = $this->twoWaits()->run(ExecutionRequest::resume(['n' => 2]));
        $nextRun = $this->twoWaits()->run();

        $this->assertSame(1, $first->getInterruptRequest()->getId());
        $this->assertSame('first', $this->eventName($first->getInterruptRequest()));
        $this->assertSame(2, $second->getInterruptRequest()->getId());
        $this->assertSame('second', $this->eventName($second->getInterruptRequest()));
        $this->assertSame(['n' => 1], $completed->get('first'));
        $this->assertSame(['n' => 2], $completed->get('second'));
        $this->assertSame(1, $nextRun->getInterruptRequest()->getId());
        $this->assertNotSame($first->getRunId(), $nextRun->getRunId());
    }

    public function test_a_node_cannot_choose_its_interrupt_id(): void
    {
        $node = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                $this->interrupt((new WaitForEventRequest('forged'))->withId(99));

                return new StopEvent();
            }
        };
        $workflow = Workflow::make('forged-id')->setPersistence($this->persistence)->addNode($node);

        $state = $workflow->run();

        $this->assertSame(1, $state->getInterruptRequest()->getId());
        $this->assertSame(1, $workflow->inspect()->interrupt->getId());
    }

    public function test_the_checkpoint_leaves_the_interruption_to_control(): void
    {
        $state = $this->signalWorkflow()->run();

        $raw = $this->persistence->get('signals', $state->getRunId() . '/__checkpoint');
        $checkpoint = (new PhpSerializer())->unserialize((string) $raw);

        $this->assertInstanceOf(WorkflowState::class, $checkpoint);
        $this->assertNull($checkpoint->getInterruptRequest());
        $this->assertTrue($checkpoint->get('wait_for_event_node_executed'));
        $this->assertSame('user.signup', $this->eventName($state->getInterruptRequest()));
    }

    public function test_a_mismatched_signal_is_refused_without_persisting_its_payload(): void
    {
        $this->signalWorkflow()->run();
        $before = serialize($this->persistence);

        try {
            $this->signalWorkflow()->run(ExecutionRequest::signal('user.deleted', ['forged' => true]));
            $this->fail('A signal must match the current interruption.');
        } catch (WorkflowException $e) {
            $this->assertSame("The current interruption is not waiting for signal 'user.deleted'.", $e->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence));
        $this->assertTrue($this->signalWorkflow()->run(ExecutionRequest::resume())->isInterrupted());
        $state = $this->signalWorkflow()->run(ExecutionRequest::signal('user.signup', ['id' => 7]));
        $this->assertSame(['id' => 7], $state->get('received_payload'));
    }

    public function test_a_signal_cannot_wake_a_sleep(): void
    {
        $workflow = Workflow::make('sleeping')->setPersistence($this->persistence)
            ->addNodes([new NodeOne(), new SleepUntilNode(new DateTimeImmutable('@1')), new NodeThree()]);
        $workflow->run();
        $before = serialize($this->persistence);

        try {
            $workflow->run(ExecutionRequest::signal('sleep_until'));
            $this->fail('Only the clock ends a sleep.');
        } catch (WorkflowException $e) {
            $this->assertSame("The current interruption is not waiting for signal 'sleep_until'.", $e->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence));
    }

    public function test_an_answer_needs_a_current_interruption(): void
    {
        $failing = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                throw new RuntimeException('node failed');
            }
        };
        $workflow = Workflow::make('no-interruption')->setPersistence($this->persistence)->addNode($failing);
        try {
            $workflow->run();
            $this->fail('Expected the node failure.');
        } catch (RuntimeException) {
        }
        $before = serialize($this->persistence);

        try {
            $workflow->run(ExecutionRequest::resume(['unsolicited' => true]));
            $this->fail('An answer without a question must be refused.');
        } catch (WorkflowException $e) {
            $this->assertSame('There is no current interruption to answer.', $e->getMessage());
        }

        $this->assertSame($before, serialize($this->persistence));
    }

    public function test_a_run_that_may_be_executing_refuses_another_answer(): void
    {
        $persistence = new CrashAfterClaim();
        $make = fn (): Workflow => Workflow::make('claimed')->setPersistence($persistence)
            ->addNodes([new NodeOne(), new WaitForEventNode(), new NodeThree()]);
        $make()->run();
        $persistence->crash = true;
        try {
            $make()->run(ExecutionRequest::resume(['id' => 1]));
            $this->fail('Expected the injected crash after the claim.');
        } catch (RuntimeException $e) {
            $this->assertSame('Lost claim acknowledgement', $e->getMessage());
        }
        $before = serialize($persistence);

        foreach ([['id' => 1], ['id' => 2]] as $payload) {
            try {
                $make()->run(ExecutionRequest::resume($payload));
                $this->fail('A claimed run must not accept an answer.');
            } catch (WorkflowException $e) {
                $this->assertMatchesRegularExpression("/^Run 'run_[^']+' is 'running', not suspended\\.$/", $e->getMessage());
            }
        }

        $this->assertSame($before, serialize($persistence));
        $this->assertSame(['id' => 1], $make()->run(ExecutionRequest::resume())->get('received_payload'));
    }

    public function test_a_failed_branch_keeps_the_interruption_of_its_sibling(): void
    {
        $trace = (object) ['events' => [], 'fail' => true];
        $make = fn (): Workflow => $this->failingSiblingWorkflow($trace);

        try {
            $make()->run();
            $this->fail('Expected the image branch to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('image failed', $e->getMessage());
        }
        $failed = $make()->inspect();
        $this->assertSame(WorkflowStatus::Failed, $failed->status);
        $this->assertSame('text', $this->eventName($failed->interrupt));

        // Polling without an answer settles the run as suspended and runs nothing.
        $trace->fail = false;
        $polled = $make()->run(ExecutionRequest::resume());
        $this->assertTrue($polled->isInterrupted());
        $this->assertSame(1, $polled->getInterruptRequest()->getId());
        $this->assertSame(WorkflowStatus::Suspended, $make()->inspect()->status);
        $this->assertSame(['text.started', 'image.started'], $trace->events);

        $completed = $make()->run(ExecutionRequest::resume(['approved' => true]));
        $this->assertSame(['text' => ['approved' => true], 'image' => 'image done'], $completed->get('analysis'));
        $this->assertSame(['text.started', 'image.started', 'text.started', 'image.started'], $trace->events);
    }

    public function test_an_answer_recovers_a_failed_run_in_one_continuation(): void
    {
        $trace = (object) ['events' => [], 'fail' => true];
        try {
            $this->failingSiblingWorkflow($trace)->run();
            $this->fail('Expected the image branch to fail.');
        } catch (RuntimeException) {
        }
        $trace->fail = false;

        $completed = $this->failingSiblingWorkflow($trace)->run(ExecutionRequest::resume(['approved' => true]));

        $this->assertFalse($completed->isInterrupted());
        $this->assertSame(['text' => ['approved' => true], 'image' => 'image done'], $completed->get('analysis'));
    }

    public function test_a_superseded_segment_cannot_complete_the_run(): void
    {
        $persistence = new ClaimBeforeCleanupPersistence();
        $persistence->claimBeforeCleanup = true;
        $workflow = Workflow::make('superseded')->setPersistence($persistence)->addNode(new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
            {
                return new StopEvent();
            }
        });

        try {
            $workflow->run();
            $this->fail('A segment that lost the run must not report completion.');
        } catch (WorkflowException $e) {
            $this->assertSame("Stale execution attempt 1 cannot complete workflow ID 'superseded'.", $e->getMessage());
        }

        $run = $workflow->inspect();
        $this->assertSame(WorkflowStatus::Running, $run->status);
        $this->assertSame(2, $run->executionAttempt);
    }

    protected function failingSiblingWorkflow(stdClass $trace): Workflow
    {
        $fork = new class () extends Node {
            public function __invoke(StartEvent $event, WorkflowState $state): DocumentParallelEvent
            {
                return new DocumentParallelEvent(['text' => new TextProcessEvent(), 'image' => new ImageProcessEvent()]);
            }
        };
        $text = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(TextProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'text.started';
                if (!$this->isResuming()) {
                    delay(0.001);
                }

                return new StopEvent($this->awaitEvent('text'));
            }
        };
        $image = new class ($trace) extends Node {
            public function __construct(protected stdClass $trace)
            {
            }

            public function __invoke(ImageProcessEvent $event, WorkflowState $state): StopEvent
            {
                $this->trace->events[] = 'image.started';
                delay(0.005);
                if ($this->trace->fail) {
                    throw new RuntimeException('image failed');
                }

                return new StopEvent('image done');
            }
        };

        return Workflow::make('failing-sibling')->setPersistence($this->persistence)
            ->setBranchRunner(new AsyncBranchRunner())
            ->addNodes([$fork, $text, $image, new MergeNode()]);
    }

    protected function eventName(?InterruptRequest $request): string
    {
        $this->assertInstanceOf(WaitForEventRequest::class, $request);

        return $request->getEventName();
    }
}
