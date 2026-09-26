<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Exceptions;

use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function date;
use function time;

class RunInFlightExceptionTest extends TestCase
{
    protected const PREFIX = "Cannot ignite a new run for workflow ID 'thread-1': run 'run-a' (attempt 2)";

    public function test_keeps_the_portable_identity_of_the_blocking_generation(): void
    {
        $interrupt = (new WaitForEventRequest('payment'))->withId(4);

        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Suspended, 2, 1_900_000_000, $interrupt);

        $this->assertSame('thread-1', $exception->workflowId);
        $this->assertSame('run-a', $exception->runId);
        $this->assertSame(WorkflowStatus::Suspended, $exception->status);
        $this->assertSame(2, $exception->executionAttempt);
        $this->assertSame(1_900_000_000, $exception->leaseExpiresAt);
        $this->assertSame($interrupt, $exception->interrupt);
    }

    /**
     * @return iterable<string, array{InterruptRequest, string}>
     */
    public static function suspensions(): iterable
    {
        $deadline = new DateTimeImmutable('2031-01-02T03:04:05+00:00');

        yield 'event' => [(new WaitForEventRequest('payment'))->withId(4), "#4 wait_for_event 'payment'"];
        yield 'expiring event' => [
            (new WaitForEventRequest('payment', $deadline))->withId(4),
            "#4 wait_for_event 'payment' expiring 2031-01-02T03:04:05+00:00",
        ];
        yield 'approval' => [(new ApprovalRequest('Approve?'))->withId(1), "#1 wait_for_event 'approval'"];
        yield 'timer' => [(new SleepUntilRequest($deadline))->withId(7), '#7 sleep_until 2031-01-02T03:04:05+00:00'];
    }

    #[DataProvider('suspensions')]
    public function test_a_suspended_run_names_its_wait_and_how_to_resume_it(InterruptRequest $interrupt, string $wait): void
    {
        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Suspended, 2, interrupt: $interrupt);

        $this->assertSame(
            self::PREFIX . " is suspended, waiting on {$wait}. "
            . 'Deliver the awaited input with run(ExecutionRequest::resume($payload)), or evaluate due deadlines '
            . 'with run(ExecutionRequest::resume()), before igniting again.',
            $exception->getMessage()
        );
    }

    public function test_a_retained_completion_points_to_acknowledge(): void
    {
        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Completed, 2);

        $this->assertSame(
            self::PREFIX . " completed and its outcome is retained. Call acknowledge('run-a') to release the workflow ID.",
            $exception->getMessage()
        );
    }

    public function test_a_failed_run_changed_concurrently_asks_for_a_retry(): void
    {
        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Failed, 2);

        $this->assertSame(
            self::PREFIX . ' failed, but a concurrent process changed it while it was being superseded. Retry the ignition.',
            $exception->getMessage()
        );
    }

    public function test_a_running_run_without_lease_explains_takeover(): void
    {
        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Running, 2);

        $this->assertSame(
            self::PREFIX . ' is marked running with no lease, so a crashed process cannot be told apart from a live one. '
            . 'If it died, run(ExecutionRequest::resume()) takes the run over; setLeaseTimeout() lets a later ignition '
            . 'supersede dead runs automatically.',
            $exception->getMessage()
        );
    }

    public function test_a_running_run_with_a_live_lease_names_its_expiry(): void
    {
        $leaseExpiresAt = time() + 3600;

        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Running, 2, $leaseExpiresAt);

        $this->assertSame(
            self::PREFIX . ' is executing and holds a lease until ' . date(DateTimeInterface::ATOM, $leaseExpiresAt)
            . '. Wait for it to settle, or ignite again after the lease expires to supersede it.',
            $exception->getMessage()
        );
    }

    public function test_a_running_run_with_an_expired_lease_asks_for_a_retry(): void
    {
        $exception = new RunInFlightException('thread-1', 'run-a', WorkflowStatus::Running, 2, time() - 1);

        $this->assertSame(
            self::PREFIX . ' holds an expired lease, but a concurrent process changed it while it was being superseded. Retry the ignition.',
            $exception->getMessage()
        );
    }
}
