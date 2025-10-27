<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;

trait ResolveState
{
    protected function state(): AgentState
    {
        $state = new AgentState();
        $state->setChatHistory($this->chatHistory());
        return $state;
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
