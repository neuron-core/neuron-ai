<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\WorkflowStatus;

use function date;
use function time;

/**
 * A new run was refused because the workflow ID is held by a generation that
 * is not dead: a live pause, an executing attempt, an unacknowledged outcome,
 * or a generation another process claimed during the sweep. The fields are
 * that generation's portable identity; the message names the verb that
 * settles it. A reserved start, such as a queue job reusing its delivery ID,
 * carries the run ID it reserved, so the message can tell a redelivery of
 * that very run from a run started by someone else.
 */
class RunInFlightException extends WorkflowRefusedException
{
    public function __construct(
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt,
        public readonly ?int $leaseExpiresAt = null,
        public readonly ?InterruptRequest $interrupt = null,
        public readonly ?string $reservedRunId = null,
    ) {
        parent::__construct(
            "Cannot ignite a new run for workflow ID '{$this->workflowId}': " . $this->describeGeneration(),
            RefusalReason::RunInFlight,
        );
    }

    protected function describeGeneration(): string
    {
        $run = "run '{$this->runId}' (attempt {$this->executionAttempt})";

        if ($this->reservedRunId === $this->runId) {
            return "{$run} is the one this reserved start ignited before. " . $this->reachOwnRun();
        }

        if ($this->reservedRunId !== null) {
            return "{$run} holds it, and a reserved start never replaces another run. " . $this->settleOtherRun();
        }

        return match ($this->status) {
            WorkflowStatus::Suspended => "{$run} is suspended, waiting on {$this->describeInterrupt($this->interrupt)}. "
                . 'Deliver the awaited input with run(ExecutionRequest::resume($payload)), or evaluate due deadlines '
                . 'with run(ExecutionRequest::resume()), before igniting again.',
            WorkflowStatus::Completed => "{$run} completed and its outcome is retained. "
                . "Call acknowledge('{$this->runId}') to release the workflow ID.",
            WorkflowStatus::Running => $this->describeRunning($run),
            WorkflowStatus::Failed => "{$run} failed, but a concurrent process changed it while it was "
                . 'being superseded. Retry the ignition.',
        };
    }

    /**
     * A redelivered reserved start reaches the run it ignited through a fenced continuation.
     */
    protected function reachOwnRun(): string
    {
        $fence = "'{$this->runId}', {$this->executionAttempt}";

        return match ($this->status) {
            WorkflowStatus::Suspended => "It is waiting on {$this->describeInterrupt($this->interrupt)}: "
                . "deliver the awaited input with run(ExecutionRequest::resume(\$payload, {$fence})).",
            WorkflowStatus::Completed => 'Its outcome is retained: replay it with '
                . "run(ExecutionRequest::resume(expectedRunId: '{$this->runId}')), record it, then call acknowledge('{$this->runId}').",
            WorkflowStatus::Running => $this->holdsLiveLease()
                ? "It is executing and holds a lease until {$this->leaseExpiry()}: if its process died, deliver the start "
                    . 'again with recoverFailed: true once the lease expires.'
                : "If its process died, recover it with run(ExecutionRequest::resume(null, {$fence}))"
                    . ($this->leaseExpiresAt === null ? '.' : ', or deliver the start with recoverFailed: true.'),
            WorkflowStatus::Failed => "It failed: recover it with run(ExecutionRequest::resume(null, {$fence})), "
                . 'or deliver the start with recoverFailed: true.',
        };
    }

    protected function settleOtherRun(): string
    {
        $fence = "'{$this->runId}', {$this->executionAttempt}";

        return match ($this->status) {
            WorkflowStatus::Suspended => "Answer its wait on {$this->describeInterrupt($this->interrupt)}, "
                . "or discard it with abandon({$fence}).",
            WorkflowStatus::Completed => 'Its outcome is retained: read it with '
                . "run(ExecutionRequest::resume(expectedRunId: '{$this->runId}')), then call acknowledge('{$this->runId}').",
            WorkflowStatus::Running => $this->holdsLiveLease()
                ? "It is executing and holds a lease until {$this->leaseExpiry()}: wait for it to settle."
                : "If its process died, recover it with run(ExecutionRequest::resume(null, {$fence})), "
                    . "or discard it with abandon({$fence}).",
            WorkflowStatus::Failed => "It failed: recover it with run(ExecutionRequest::resume(null, {$fence})), "
                . "or discard it with abandon({$fence}).",
        };
    }

    protected function holdsLiveLease(): bool
    {
        return $this->leaseExpiresAt !== null && $this->leaseExpiresAt > time();
    }

    protected function leaseExpiry(): string
    {
        return date(DateTimeInterface::ATOM, (int) $this->leaseExpiresAt);
    }

    protected function describeRunning(string $run): string
    {
        if ($this->leaseExpiresAt === null) {
            return "{$run} is marked running with no lease, so a crashed process cannot be told apart "
                . 'from a live one. If it died, run(ExecutionRequest::resume()) takes the run over; setLeaseTimeout() lets a '
                . 'later ignition supersede dead runs automatically.';
        }

        if ($this->leaseExpiresAt > time()) {
            $expiresAt = date(DateTimeInterface::ATOM, $this->leaseExpiresAt);

            return "{$run} is executing and holds a lease until {$expiresAt}. Wait for it to settle, "
                . 'or ignite again after the lease expires to supersede it.';
        }

        return "{$run} holds an expired lease, but a concurrent process changed it while it was "
            . 'being superseded. Retry the ignition.';
    }

    protected function describeInterrupt(?InterruptRequest $request): string
    {
        // The engine never writes one, but a corrupted record must still yield this exception
        if (!$request instanceof InterruptRequest) {
            return 'an interrupt missing from its control record';
        }

        $description = "#{$request->getId()} {$request->type()->value}";

        if ($request instanceof WaitForEventRequest) {
            $description .= " '{$request->getEventName()}'";
            $expiresAt = $request->getExpiresAt();
            if ($expiresAt instanceof DateTimeImmutable) {
                $description .= ' expiring ' . $expiresAt->format(DateTimeInterface::ATOM);
            }
        } elseif ($request instanceof SleepUntilRequest) {
            $description .= ' ' . $request->getWakeAt()->format(DateTimeInterface::ATOM);
        }

        return $description;
    }
}
