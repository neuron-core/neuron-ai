<?php

declare(strict_types=1);

namespace NeuronAI\WorkflowV2;

use NeuronAI\WorkflowV2\Persistence\PersistenceInterface;

class WorkflowContext
{
    protected bool $isResuming = false;
    protected array $feedback = [];

    public function __construct(
        protected string $workflowId,
        protected string $currentNode,
        protected PersistenceInterface $persistence,
        protected WorkflowState $currentState
    ) {
    }

    public function interrupt(array $data): mixed
    {
        if ($this->isResuming && isset($this->feedback[$this->currentNode])) {
            return $this->feedback[$this->currentNode];
        }

        throw new WorkflowInterrupt($data, $this->currentNode, $this->currentState);
    }

    public function setResuming(bool $resuming, array $feedback = []): void
    {
        $this->isResuming = $resuming;
        $this->feedback = $feedback;
    }

    public function setCurrentState(WorkflowState $state): WorkflowContext
    {
        $this->currentState = $state;
        return $this;
    }

    public function setCurrentNode(string $node): WorkflowContext
    {
        $this->currentNode = $node;
        return $this;
    }
}
