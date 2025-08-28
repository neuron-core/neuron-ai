<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

abstract class Node implements NodeInterface
{
    protected WorkflowState $currentState;
    protected Event $currentEvent;
    protected bool $isResuming = false;
    protected array $feedback = [];

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
            return $this->feedback[static::class];
        }

        throw new WorkflowInterrupt($data, $this, $this->currentState, $this->currentEvent);
    }
}
