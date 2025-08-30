<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

abstract class Node implements NodeInterface
{
    protected WorkflowState $currentState;
    protected Event $currentEvent;
    protected bool $isResuming = false;
    protected array $feedback = [];

    public function run(Event $event, WorkflowState $state): \Generator|Event
    {
        /** @phpstan-ignore method.notFound */
        return $this->__invoke($event, $state);
    }

    public function setWorkflowContext(
        WorkflowState $currentState,
        Event $currentEvent,
        bool $isResuming = false,
        array $feedback = []
    ): void {
        $this->currentState = $currentState;
        $this->currentEvent = $currentEvent;
        $this->isResuming = $isResuming;
        $this->feedback = $feedback;
    }

    /**
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    protected function interrupt(array $data): mixed
    {
        if ($this->isResuming && isset($this->feedback[static::class])) {
            $feedback = $this->feedback[static::class];
            // Clear both feedback and resuming state after use to allow subsequent interrupts
            unset($this->feedback[static::class]);
            $this->isResuming = false;
            return $feedback;
        }

        throw new WorkflowInterrupt($data, $this, $this->currentState, $this->currentEvent);
    }
}
