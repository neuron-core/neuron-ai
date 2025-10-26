<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
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
        $state = new AgentState();
        $state->setChatHistory($this->chatHistory());
        return $state;
    }

    /**
     * Get the current instance of the chat history.
     */
    public function resolveAgentState(): AgentState
    {
        return $this->state ?? $this->state = $this->agentState();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->resolveAgentState()->setChatHistory($chatHistory);
        return $this;
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->resolveAgentState()->getChatHistory();
    }
}
