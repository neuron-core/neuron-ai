<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

class WorkflowInterrupt extends WorkflowException implements \JsonSerializable
{
    public function __construct(
        protected array $data,
        protected NodeInterface $currentNode,
        protected WorkflowState $state,
        protected Event $currentEvent
    ) {
        parent::__construct('Workflow interrupted for human input');
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getCurrentNode(): NodeInterface
    {
        return $this->currentNode;
    }

    public function getState(): WorkflowState
    {
        return $this->state;
    }

    public function getCurrentEvent(): Event
    {
        return $this->currentEvent;
    }

    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'data' => $this->data,
            'currentNode' => \serialize($this->currentNode),
            'state' => $this->state->all(),
            'currentEvent' => \serialize($this->currentEvent),
        ];
    }

    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        $this->message = $data['message'];
        $this->data = $data['data'];
        $this->currentNode = \unserialize($data['currentNode']);
        $this->state = new WorkflowState($data['state']);
        $this->currentEvent = \unserialize($data['currentEvent']);
    }
}
