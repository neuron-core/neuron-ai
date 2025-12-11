<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowHandler;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

class AgentHandler extends WorkflowHandler
{
    public function __construct(
        protected WorkflowHandler $workflowHandler,
        protected Agent $agent
    ) {
        parent::__construct($this->agent);
    }

    // Inherit WorkflowHandler methods
    public function getResult(): WorkflowState
    {
        return $this->workflowHandler->getResult();
    }

    public function streamEvents(?StreamAdapterInterface $adapter = null): Generator
    {
        return $this->workflowHandler->streamEvents($adapter);
    }

    /**
     * Agent convenience method
     *
     * @throws Throwable
     * @throws WorkflowException
     * @throws WorkflowInterrupt
     */
    public function getMessage(): Message
    {
        /** @var AgentState $state */
        $state = $this->getResult(); // Blocks until complete
        return $state->getChatHistory()->getLastMessage();
    }
}
