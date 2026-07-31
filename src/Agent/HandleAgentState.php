<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;

trait HandleAgentState
{
    protected ChatHistoryInterface $chatHistory;

    protected function state(): AgentState
    {
        return new AgentState();
    }

    protected function chatHistory(): ChatHistoryInterface
    {
        return new InMemoryChatHistory();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): self
    {
        $this->chatHistory = $chatHistory;
        return $this;
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory ??= $this->chatHistory();
    }
}
