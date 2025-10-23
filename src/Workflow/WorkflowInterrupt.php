<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Exception thrown when a workflow needs human input.
 *
 * Contains:
 * - InterruptRequest: Structured actions requiring approval
 * - Node context: Class and checkpoints for resumption
 * - Workflow state: Current state
 * - Event: The event being processed when interrupted
 */
class WorkflowInterrupt extends WorkflowException implements \JsonSerializable
{
    /**
     * @param InterruptRequest $request Structured interrupt request with actions
     * @param class-string<NodeInterface> $nodeClass Node class name
     * @param array<string, mixed> $nodeCheckpoints Node checkpoint state
     * @param WorkflowState $state Workflow state
     * @param Event $currentEvent Current event
     */
    public function __construct(
        protected InterruptRequest $request,
        protected string $nodeClass,
        protected array $nodeCheckpoints,
        protected WorkflowState $state,
        protected Event $currentEvent
    ) {
        parent::__construct('Workflow interrupted for human input');
    }

    /**
     * Get the structured interrupt request.
     */
    public function getRequest(): InterruptRequest
    {
        return $this->request;
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
            'request' => $this->request->jsonSerialize(),
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
        $this->request = InterruptRequest::fromArray($data['request']);
        $this->nodeClass = $data['nodeClass'];
        $this->nodeCheckpoints = $data['nodeCheckpoints'];
        $this->state = new $data['state_class']($data['state']);
        $this->currentEvent = \unserialize($data['currentEvent']);
    }
}

