<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\WorkflowState;

class StepResult
{
    public function __construct(
        protected string $stepId,
        protected ?Event $event = null,
        protected ?WorkflowState $state = null,
        /**
         * Marker that this step is suspended waiting for a resume payload. Only the flag
         * is persisted — the InterruptRequest itself is NOT stored (it is outbound-only
         * and rebuilt by re-running the node on resume), so developer objects stuffed
         * into a request are never serialized.
         */
        protected bool $interrupted = false,
        protected ?int $suspensionId = null,
        /**
         * Failure marker for crash observability: ['message' => string, 'class' => string].
         * Null unless this step recorded an unhandled throwable.
         */
        protected ?array $error = null,
    ) {
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }

    /**
     * The completed step's terminal event. Only marker records (interrupted,
     * failed) carry none — consuming one as a completed result is an executor
     * bug, so this throws instead of null-propagating.
     *
     * @throws WorkflowException
     */
    public function getEvent(): Event
    {
        if (!$this->event instanceof Event) {
            throw new WorkflowException("Step {$this->stepId} is a marker record and carries no event.");
        }

        return $this->event;
    }

    /**
     * A copy of this result carrying the given event — used at the
     * deserialization boundary, where the workflow restores a recalled
     * event's transient capability before it re-enters traversal.
     */
    public function withEvent(Event $event): static
    {
        $clone = clone $this;
        $clone->event = $event;

        return $clone;
    }

    /**
     * The completed step's resulting state. Marker records carry none — see
     * getEvent().
     *
     * @throws WorkflowException
     */
    public function getState(): WorkflowState
    {
        if (!$this->state instanceof WorkflowState) {
            throw new WorkflowException("Step {$this->stepId} is a marker record and carries no state.");
        }

        return $this->state;
    }

    public function isInterrupted(): bool
    {
        return $this->interrupted;
    }

    public function getSuspensionId(): ?int
    {
        return $this->suspensionId;
    }

    /**
     * Failure metadata for a crashed step, or null when the step did not fail.
     *
     * @return array{message: string, class: string}|null
     */
    public function getError(): ?array
    {
        return $this->error;
    }

    public function isFailed(): bool
    {
        return $this->error !== null;
    }

    public function __serialize(): array
    {
        return [
            'version' => 5,
            'stepId' => $this->stepId,
            'event' => $this->event,
            'state' => $this->state,
            'interrupted' => $this->interrupted,
            'suspensionId' => $this->suspensionId,
            'error' => $this->error,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->stepId = $data['stepId'];
        $this->event = $data['event'] ?? null;
        $this->state = $data['state'] ?? null;
        $this->interrupted = $data['interrupted'] ?? false;
        $this->suspensionId = $data['suspensionId'] ?? null;
        $this->error = $data['error'] ?? null;
    }
}
