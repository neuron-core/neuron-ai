<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Persistence\PersistenceInterface;

class WorkflowContext
{
    protected bool $isResuming = false;
    protected array $feedback = [];

    public function __construct(
        protected string $workflowId,
        protected NodeInterface $currentNode,
        protected PersistenceInterface $persistence,
        protected WorkflowState $currentState,
        protected Event $currentEvent
    ) {
    }

    public function interrupt(array $data): mixed
    {
        if ($this->isResuming && isset($this->feedback[$this->currentNode::class])) {
            return $this->feedback[$this->currentNode::class];
        }

        throw new WorkflowInterrupt($data, $this->currentNode, $this->currentState, $this->currentEvent);
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

    public function setCurrentNode(NodeInterface $node): WorkflowContext
    {
        $this->currentNode = $node;
        return $this;
    }
}
