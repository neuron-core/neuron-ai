<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;

class WorkflowInterrupt extends WorkflowException implements \JsonSerializable
{
    /**
     * @param array<string, mixed> $data Interrupt data
     * @param class-string<NodeInterface> $nodeClass Node class name
     * @param array<string, mixed> $nodeCheckpoints Node checkpoint state
     * @param WorkflowState $state Workflow state
     * @param Event $currentEvent Current event
     */
    public function __construct(
        protected array $data,
        protected string $nodeClass,
        protected array $nodeCheckpoints,
        protected WorkflowState $state,
        protected Event $currentEvent
    ) {
        parent::__construct('Workflow interrupted for human input');
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return class-string<NodeInterface>
     */
    public function getNodeClass(): string
    {
        return $this->nodeClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function getNodeCheckpoints(): array
    {
        return $this->nodeCheckpoints;
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
            'nodeClass' => $this->nodeClass,
            'nodeCheckpoints' => $this->nodeCheckpoints,
            'state' => $this->state->all(),
            'state_class' => $this->state::class,
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
        $this->nodeClass = $data['nodeClass'];
        $this->nodeCheckpoints = $data['nodeCheckpoints'];
        $this->state = new $data['state_class']($data['state']);
        $this->currentEvent = \unserialize($data['currentEvent']);
    }
}
