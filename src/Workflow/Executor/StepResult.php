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
        /**
         * Memoized value carried by a durable memo step (see Node::memoize() / StepMemoizer).
         * Null for regular node-execution steps.
         */
        protected mixed $output = null,
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

    /**
     * The memoized value carried by a durable memo step.
     */
    public function getOutput(): mixed
    {
        return $this->output;
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
            'version' => 1,
            'stepId' => $this->stepId,
            'event' => $this->event?->toSnapshot(),
            'state' => $this->state,
            'interrupt' => $this->interrupt,
            'output' => $this->output,
            'error' => $this->error,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->stepId = $data['stepId'];
        $this->event = isset($data['event']) ? Event::fromSnapshot($data['event']) : null;
        $this->state = $data['state'] ?? null;
        $this->interrupt = $data['interrupt'] ?? null;
        $this->output = $data['output'] ?? null;
        $this->error = $data['error'] ?? null;
    }
}
