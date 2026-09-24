<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Events\InterruptEvent;
use NeuronAI\Workflow\WorkflowState;

class StepResult
{
    public function __construct(
        protected string $stepId,
        protected ?Event $event = null,
        protected ?WorkflowState $state = null,
    ) {
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }

    /**
     * The step's terminal event, including the persisted request when interrupted.
     *
     * @throws WorkflowException
     */
    public function getEvent(): Event
    {
        if (!$this->event instanceof Event) {
            throw new WorkflowException("Step {$this->stepId} carries no event.");
        }

        return $this->event;
    }

    public function withState(WorkflowState $state): static
    {
        $clone = clone $this;
        $clone->state = $state;

        return $clone;
    }

    /**
     * The step's resulting state. Interrupted markers retain their state so
     * a deferred interruption can be replayed without invoking its node.
     *
     * @throws WorkflowException
     */
    public function getState(): WorkflowState
    {
        if (!$this->state instanceof WorkflowState) {
            throw new WorkflowException("Step {$this->stepId} carries no state.");
        }

        return $this->state;
    }

    public function isInterrupted(): bool
    {
        return $this->event instanceof InterruptEvent;
    }

    public function getInterruptId(): ?int
    {
        return $this->event instanceof InterruptEvent ? $this->event->request->getId() : null;
    }

    public function __serialize(): array
    {
        return [
            'stepId' => $this->stepId,
            'event' => $this->event,
            'state' => $this->state,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->stepId = $data['stepId'];
        $this->event = $data['event'] ?? null;
        $this->state = $data['state'] ?? null;
    }
}
