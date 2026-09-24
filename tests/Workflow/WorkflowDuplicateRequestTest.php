<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Tests\Workflow\Executor\Stub\CrashAfterClaim;
use NeuronAI\Tests\Workflow\Stub\KeyedWorkflow;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Persistence\InMemoryPersistence;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A retried request never executes twice: a reserved run ID refuses a second
 * ignition, and the run and attempt a caller observed refuse a second answer.
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
}
