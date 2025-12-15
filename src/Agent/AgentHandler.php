<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowHandlerInterface;
use NeuronAI\Workflow\WorkflowInterrupt;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

class AgentHandler implements WorkflowHandlerInterface
{
    public function __construct(
        protected WorkflowHandlerInterface $workflowHandler
    ) {
    }

    /**
     * @throws Throwable
     */
    public function getResult(): WorkflowState
    {
        return $this->workflowHandler->getResult();
    }

    /**
     * @throws Throwable
     */
    public function events(?StreamAdapterInterface $adapter = null): Generator
    {
        return $this->workflowHandler->events($adapter);
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
        return $state->getMessage();
    }
}
