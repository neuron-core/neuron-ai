<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\WorkflowHandler;
use NeuronAI\Workflow\WorkflowInterrupt;
use Throwable;

class AgentHandler extends WorkflowHandler
{
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
        $state = $this->run(); // Blocks until complete
        return $state->getMessage();
    }
}
