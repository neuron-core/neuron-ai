<?php

declare(strict_types=1);

namespace NeuronAI\Exceptions;

use DateTimeImmutable;
use DateTimeInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\WorkflowStatus;

use function array_map;
use function count;
use function date;
use function implode;
use function time;

/**
 * A new run was refused because the workflow ID is held by a generation that
 * is not dead: a live pause, an executing attempt, an unacknowledged outcome,
 * or a generation another process claimed during the sweep. The fields are
 * that generation's portable identity; the message names the verb that
 * settles it.
 */
class RunInFlightException extends WorkflowException
{
    /**
     * @param array<int, InterruptRequest> $interrupts
     */
    public function __construct(
        public readonly string $workflowId,
        public readonly string $runId,
        public readonly WorkflowStatus $status,
        public readonly int $executionAttempt,
        public readonly ?int $leaseExpiresAt = null,
        public readonly array $interrupts = [],
    ) {
        parent::__construct(
            "Cannot ignite a new run for workflow ID '{$this->workflowId}': " . $this->describeGeneration()
        );
    }

    protected function describeGeneration(): string
    {
        $run = "run '{$this->runId}' (attempt {$this->executionAttempt})";

        return match ($this->status) {
            WorkflowStatus::Suspended => "{$run} is suspended, waiting on {$this->describeInterrupts()}. "
                . 'Deliver the awaited input with signal() or run($inputs), or evaluate due deadlines '
                . 'with run([]), before igniting again.',
            WorkflowStatus::Completed => "{$run} completed and its outcome is retained. "
                . "Call acknowledgeCompletion('{$this->runId}') to release the workflow ID.",
            WorkflowStatus::Running => $this->describeRunning($run),
            WorkflowStatus::Failed => "{$run} failed, but a concurrent process changed it while it was "
                . 'being superseded. Retry the ignition.',
        };
    }

    protected function describeRunning(string $run): string
    {
        if ($this->leaseExpiresAt === null) {
            return "{$run} is marked running with no lease, so a crashed process cannot be told apart "
                . 'from a live one. If it died, run([]) takes the run over; setLeaseTimeout() lets a '
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

    protected function describeInterrupts(): string
    {
        $descriptions = array_map(
            $this->describeInterrupt(...),
            $this->interrupts,
        );

        return count($this->interrupts) . ' interrupt(s): ' . implode(', ', $descriptions);
    }

    protected function describeInterrupt(InterruptRequest $request): string
    {
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
