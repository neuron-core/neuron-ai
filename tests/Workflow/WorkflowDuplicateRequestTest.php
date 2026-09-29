<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\CrashAfterClaim;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Events\StopEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\WorkflowControl;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\Persistence\PhpSerializer;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowState;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ArrayObject;

use function time;

/**
 * A retried request never executes twice: a reserved run ID refuses a second
 * ignition, and the run and attempt a caller observed refuse a second answer.
 * A reserved start asking to recover finishes its own dead run instead.
 */
class WorkflowDuplicateRequestTest extends TestCase
{
    public function test_a_retried_reserved_start_is_refused_naming_its_run(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence);
        $make()->run(ExecutionRequest::start(runId: 'delivery-1'));

        try {
            $make()->run(ExecutionRequest::start(runId: 'delivery-1'));
            self::fail('A retried reserved start must not ignite another run.');
        } catch (RunInFlightException $error) {
            self::assertSame('delivery-1', $error->runId);
        }
        self::assertSame(1, $make()->inspect()->executionAttempt);
    }

    public function test_a_retried_resume_carrying_the_observed_run_and_attempt_is_rejected_as_stale(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged();
        $suspended = $make()->run(ExecutionRequest::start());
        $resume = ExecutionRequest::resume([], $suspended->getRunId(), $suspended->getExecutionAttempt());
        $completed = $make()->run($resume);

        try {
            $make()->run($resume);
            self::fail('A retried answer must not be delivered again.');
        } catch (WorkflowException $error) {
            self::assertStringContainsString('Stale continuation', $error->getMessage());
        }
        self::assertSame($completed->getExecutionAttempt(), $make()->inspect()->executionAttempt);
    }

    public function test_inputless_resume_recovers_accepted_input_after_the_claim_acknowledgement_is_lost(): void
    {
        $persistence = new CrashAfterClaim();
        $make = static fn (): KeyedWorkflow => KeyedWorkflow::make('order')->setPersistence($persistence);
        $started = $make()->run(ExecutionRequest::start());
        $persistence->crash = true;
        try {
            $make()->run(ExecutionRequest::resume([], $started->getRunId(), $started->getExecutionAttempt()));
            self::fail('Expected the injected crash.');
        } catch (RuntimeException $error) {
            self::assertSame('Lost claim acknowledgement', $error->getMessage());
        }
        self::assertSame(WorkflowStatus::Running, $make()->inspect()->status);

        $completed = $make()->run(ExecutionRequest::resume());

        self::assertSame(WorkflowStatus::Completed, $completed->getStatus());
        self::assertSame($started->getRunId(), $completed->getRunId());
        self::assertSame(3, $completed->getExecutionAttempt());
    }

    /**
     * A queue job sends the same reserved start on every delivery: a redelivery after
     * a failure finishes the run from its last committed step.
     */
    public function test_a_redelivered_reserved_start_asking_to_recover_finishes_its_own_failed_run(): void
    {
        $persistence = new InMemoryPersistence();
        $invocations = new ArrayObject();
        $make = static fn (): Workflow => Workflow::make('order')->setPersistence($persistence)->addNode(
            new class ($invocations) extends Node {
                /** @param ArrayObject<int, true> $invocations */
                public function __construct(protected ArrayObject $invocations)
                {
                }

                public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
                {
                    $this->invocations->append(true);
                    if ($this->invocations->count() === 1) {
                        throw new RuntimeException('transient');
                    }
                    return new StopEvent();
                }
            }
        );
        $delivery = ExecutionRequest::start(runId: 'delivery-1', recoverFailed: true);
        try {
            $make()->run($delivery);
            self::fail('The first delivery must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('transient', $error->getMessage());
        }

        $state = $make()->run($delivery);

        self::assertSame(WorkflowStatus::Completed, $state->getStatus());
        self::assertSame('delivery-1', $state->getRunId());
        self::assertSame(2, $state->getExecutionAttempt());
        self::assertCount(2, $invocations);
    }

    public function test_a_redelivered_reserved_start_asking_to_recover_takes_over_the_run_its_dead_worker_left(): void
    {
        $persistence = new InMemoryPersistence();
        $serializer = new PhpSerializer();
        // The worker processing delivery-1 was killed mid-step, its lease has since expired
        $persistence->initializeIfAbsent(
            'order',
            '__control',
            $serializer->serialize(new WorkflowControl('delivery-1', WorkflowStatus::Running, leaseExpiresAt: time() - 1)),
            ['__ignition' => $serializer->serialize(new Ignition('delivery-1', new StartEvent()))],
        );

        $state = KeyedWorkflow::make('order')->setPersistence($persistence)
            ->run(ExecutionRequest::start(runId: 'delivery-1', recoverFailed: true));

        self::assertTrue($state->isInterrupted());
        self::assertSame('delivery-1', $state->getRunId());
        self::assertSame(2, $state->getExecutionAttempt());
    }

    public function test_a_redelivered_reserved_start_is_told_how_to_read_its_retained_outcome(): void
    {
        $persistence = new InMemoryPersistence();
        $make = static fn (): Workflow => Workflow::make('order')->setPersistence($persistence)
            ->retainCompletionUntilAcknowledged()
            ->addNode(new class () extends Node {
                public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
                {
                    return new StopEvent('charged');
                }
            });
        $make()->run(ExecutionRequest::start(runId: 'delivery-1', recoverFailed: true));

        try {
            $make()->run(ExecutionRequest::start(runId: 'delivery-1', recoverFailed: true));
            self::fail('A retained completion must be replayed by resume(), never by another start.');
        } catch (RunInFlightException $error) {
            self::assertSame('delivery-1', $error->reservedRunId);
            self::assertStringContainsString(
                "replay it with run(ExecutionRequest::resume(expectedRunId: 'delivery-1')), record it, then call acknowledge('delivery-1').",
                $error->getMessage(),
            );
        }
        self::assertSame(WorkflowStatus::Completed, $make()->inspect()?->status);
    }

    public function test_a_reserved_start_neither_recovers_nor_replaces_a_failed_run(): void
    {
        $persistence = new InMemoryPersistence();
        $invocations = new ArrayObject();
        $make = static fn (): Workflow => Workflow::make('order')->setPersistence($persistence)->addNode(
            new class ($invocations) extends Node {
                /** @param ArrayObject<int, true> $invocations */
                public function __construct(protected ArrayObject $invocations)
                {
                }

                public function __invoke(StartEvent $event, WorkflowState $state): StopEvent
                {
                    $this->invocations->append(true);
                    if ($this->invocations->count() === 1) {
                        throw new RuntimeException('transient');
                    }
                    return new StopEvent();
                }
            }
        );
        try {
            $make()->run(ExecutionRequest::start(runId: 'delivery-1'));
            self::fail('The first delivery must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('transient', $error->getMessage());
        }

        try {
            $make()->run(ExecutionRequest::start(runId: 'delivery-2', recoverFailed: true));
            self::fail('A reserved start must not take over the failed run of another delivery.');
        } catch (RunInFlightException $error) {
            self::assertSame('delivery-1', $error->runId);
            self::assertSame(WorkflowStatus::Failed, $error->status);
        }

        self::assertCount(1, $invocations);
        self::assertSame('delivery-1', $make()->inspect()->runId);
        self::assertSame(WorkflowStatus::Failed, $make()->inspect()->status);
    }
}
