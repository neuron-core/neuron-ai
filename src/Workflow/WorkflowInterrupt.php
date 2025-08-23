<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

class WorkflowInterrupt extends WorkflowException implements \JsonSerializable
{
    public function __construct(
        protected array $data,
        protected string $currentNode,
        protected WorkflowState $state
    ) {
        parent::__construct('Workflow interrupted for human input');
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getCurrentNode(): string
    {
        return $this->currentNode;
    }

    public function getState(): WorkflowState
    {
        return $this->state;
    }

    public function jsonSerialize(): array
    {
        return [
            'message' => $this->message,
            'data' => $this->data,
            'currentNode' => $this->currentNode,
            'state' => $this->state->all(),
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
        $this->currentNode = $data['currentNode'];
        $this->state = new WorkflowState($data['state']);
    }
}
