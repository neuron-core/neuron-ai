<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

trait ResolveState
{
    /**
     * The AI provider instance.
     */
    protected AgentState $state;

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
    public function resolveAgentState(): AgentState
    {
        return $this->state ?? $this->state = $this->agentState();
    }
}
