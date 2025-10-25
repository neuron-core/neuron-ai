<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

abstract class Node implements NodeInterface
{
    protected WorkflowState $currentState;
    protected Event $currentEvent;
    protected bool $isResuming = false;
    protected ?InterruptRequest $resumeRequest = null;

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
        ?InterruptRequest $resumeRequest = null
    ): void {
        $this->currentState = $currentState;
        $this->currentEvent = $currentEvent;
        $this->isResuming = $isResuming;
        $this->resumeRequest = $resumeRequest;
    }

    /**
     * Consume the interrupt request (used internally by nodes).
     * Returns null if not resuming or no request provided.
     */
    protected function consumeResumeRequest(): ?InterruptRequest
    {
        if ($this->isResuming) {
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
    protected function interrupt(InterruptRequest $request): ?InterruptRequest
    {
        return $this->interruptIf(true, $request);
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interruptIf(callable|bool $condition, InterruptRequest $request): ?InterruptRequest
    {
        if (($feedback = $this->consumeResumeRequest()) instanceof InterruptRequest) {
            return $feedback;
        }

        $shouldInterrupt = \is_callable($condition) ? $condition() : $condition;

        if ($shouldInterrupt) {
            throw new WorkflowInterrupt(
                $request,
                $this,
                $this->currentState,
                $this->currentEvent
            );
        }

        // Condition didn't meet, continue execution
        return null;
    }

    /**
     * Check if the node is in resuming mode.
     *
     * This is useful for middleware to determine if the workflow is resuming
     * from an interruption.
     */
    public function isResuming(): bool
    {
        return $this->isResuming;
    }

    /**
     * Get the resume request if the node is resuming.
     *
     * This allows middleware to access user decisions when resuming from
     * an interruption.
     *
     * @return InterruptRequest|null The resume request or null if not resuming
     */
    public function getResumeRequest(): ?InterruptRequest
    {
        return $this->resumeRequest;
    }
}
