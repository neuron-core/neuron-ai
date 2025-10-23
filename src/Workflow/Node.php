<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

abstract class Node implements NodeInterface
{
    protected WorkflowState $currentState;
    protected Event $currentEvent;
    protected bool $isResuming = false;
    protected ?Interrupt\InterruptRequest $resumeRequest = null;

    /**
     * @var array<string, mixed>
     */
    protected array $checkpoints = [];

    public function run(Event $event, WorkflowState $state): \Generator|Event
    {
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state);
    }

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        ?Interrupt\InterruptRequest $resumeRequest = null
    ): void {
        $this->currentState = $currentState;
        $this->currentEvent = $currentEvent;
        $this->isResuming = $isResuming;
        $this->resumeRequest = $resumeRequest;
    }

    /**
     * Check if the node is resuming after an interrupt.
     */
    public function isResuming(): bool
    {
        return $this->isResuming;
    }

    /**
     * Get the interrupt request when resuming.
     * Returns null if not resuming or no request provided.
     */
    public function getResumeRequest(): ?Interrupt\InterruptRequest
    {
        return $this->resumeRequest;
    }

    /**
     * Consume the interrupt request (used internally by nodes).
     * Returns null if not resuming or no request provided.
     */
    protected function consumeResumeRequest(): ?Interrupt\InterruptRequest
    {
        if ($this->isResuming && $this->resumeRequest !== null) {
            $request = $this->resumeRequest;
            // Clear both request and resuming state after use to allow subsequent interrupts
            $this->resumeRequest = null;
            $this->isResuming = false;
            return $request;
        }

        return null;
    }

    protected function checkpoint(string $name, \Closure $closure): mixed
    {
        if (\array_key_exists($name, $this->checkpoints)) {
            $result = $this->checkpoints[$name];
            unset($this->checkpoints[$name]);
            return $result;
        }

        $result = \call_user_func($closure);
        $this->checkpoints[$name] = $result;
        return $result;
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interrupt(Interrupt\InterruptRequest $request): mixed
    {
        return $this->interruptIf(true, $request);
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interruptIf(callable|bool $condition, Interrupt\InterruptRequest $request): mixed
    {
        if ($feedback = $this->consumeResumeRequest()) {
            return $feedback;
        }

        $shouldInterrupt = \is_callable($condition) ? $condition() : $condition;

        if ($shouldInterrupt) {
            throw new WorkflowInterrupt(
                $request,
                static::class,
                $this->checkpoints,
                $this->currentState,
                $this->currentEvent
            );
        }

        // Condition didn't meet, continue execution
        return null;
    }

    /**
     * Get node checkpoints for persistence.
     *
     * @return array<string, mixed>
     */
    public function getCheckpoints(): array
    {
        return $this->checkpoints;
    }

    /**
     * Set node checkpoints when resuming.
     *
     * @param array<string, mixed> $checkpoints
     */
    public function setCheckpoints(array $checkpoints): void
    {
        $this->checkpoints = $checkpoints;
    }
}
