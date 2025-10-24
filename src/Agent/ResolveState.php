<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Workflow\WorkflowState;

trait ResolveState
{
    public function setAgentState(AgentState $state): AgentInterface
    {
        $this->state = $state;
        return $this;
    }

    protected function agentState(): AgentState
    {
        return new AgentState();
    }

    /**
     * Get the current instance of the chat history.
     */
    public function resolveAgentState(): AgentState|WorkflowState
    {
        return $this->state ?? $this->state = $this->agentState();
    }
}
