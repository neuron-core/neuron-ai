<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

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

    public function isInterrupted(): bool
    {
        return $this->interrupted;
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
            'version' => 3,
            'stepId' => $this->stepId,
            'event' => $this->event,
            'state' => $this->state,
            'interrupted' => $this->interrupted,
            'output' => $this->output,
            'error' => $this->error,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->stepId = $data['stepId'];
        $this->event = $data['event'] ?? null;
        $this->state = $data['state'] ?? null;
        $this->interrupted = $data['interrupted'] ?? false;
        $this->output = $data['output'] ?? null;
        $this->error = $data['error'] ?? null;
    }
}
