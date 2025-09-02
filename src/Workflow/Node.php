<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

abstract class Node implements NodeInterface
{
    protected WorkflowState $currentState;
    protected Event $currentEvent;
    protected bool $isResuming = false;
    protected mixed $feedback = null;

    public function run(Event $event, WorkflowState $state): \Generator|Event
    {
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state);
    }

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        mixed $feedback = null
    ): void {
        $this->currentState = $currentState;
        $this->currentEvent = $currentEvent;
        $this->isResuming = $isResuming;
        $this->feedback = $feedback;
    }

    protected function consumeInterruptFeedback(): mixed
    {
        if ($this->isResuming && !\is_null($this->feedback)) {
            $feedback = $this->feedback;
            // Clear both feedback and resuming state after use to allow subsequent interrupts
            $this->feedback = null;
            $this->isResuming = false;
            return $feedback;
        }

        return null;
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interrupt(array $data): mixed
    {
        return $this->interruptIf(true, $data);
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interruptIf(callable|bool $condition, array $data): mixed
    {
        if ($feedback = $this->consumeInterruptFeedback()) {
            return $feedback;
        }

        $shouldInterrupt = \is_callable($condition) ? $condition() : $condition;

        if ($shouldInterrupt) {
            throw new WorkflowInterrupt($data, $this, $this->currentState, $this->currentEvent);
        }

        // Condition didn't meet, continue execution
        return null;
    }
}
