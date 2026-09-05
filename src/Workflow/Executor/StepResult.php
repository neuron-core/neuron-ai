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
         * The active interrupt associated with this incomplete step. The request
         * itself lives in WorkflowControl; this marker connects replay to it.
         */
        protected ?int $interruptId = null,
    ) {
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }

    /**
     * The completed step's terminal event. Interrupted marker records
     * carry none — consuming one as a completed result is an executor
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
     * The step's resulting state. Interrupted markers retain their state so
     * an unaddressed interruption can be replayed without invoking its node.
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
        return $this->interruptId !== null;
    }

    public function getInterruptId(): ?int
    {
        return $this->interruptId;
    }

    public function __serialize(): array
    {
        return [
            'stepId' => $this->stepId,
            'event' => $this->event,
            'state' => $this->state,
            'interruptId' => $this->interruptId,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->stepId = $data['stepId'];
        $this->event = $data['event'] ?? null;
        $this->state = $data['state'] ?? null;
        $this->interruptId = $data['interruptId'] ?? null;
    }
}
