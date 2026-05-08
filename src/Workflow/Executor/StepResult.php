<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;

class StepResult
{
    public function __construct(
        protected string $stepId,
        protected ?Event $event = null,
        protected ?WorkflowState $state = null,
        protected ?InterruptRequest $interrupt = null,
        protected int $generation = 0,
    ) {
    }

    public function getStepId(): string
    {
        return $this->stepId;
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function getState(): ?WorkflowState
    {
        return $this->state;
    }

    public function getInterrupt(): ?InterruptRequest
    {
        return $this->interrupt;
    }

    public function isInterrupted(): bool
    {
        return $this->interrupt instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }
}
