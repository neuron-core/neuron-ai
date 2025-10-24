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
    public function __construct(
        protected InterruptRequest $request,
        protected NodeInterface $node,
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

    public function getNode(): NodeInterface
    {
        return $this->node;
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
            'node' => \serialize($this->node),
            'state' => \serialize($this->state),
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
        $this->node = \unserialize($data['node']);
        $this->state = \unserialize($data['state']);
        $this->currentEvent = \unserialize($data['currentEvent']);
    }
}
