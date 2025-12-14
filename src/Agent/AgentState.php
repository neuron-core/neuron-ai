<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Workflow\WorkflowState;

/**
 * Extends WorkflowState with agent-specific state management.
 */
class AgentState extends WorkflowState
{
    protected ChatHistoryInterface $chatHistory;

    public function getMessage(): Message
    {
        return $this->getChatHistory()->getLastMessage();
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory ?? $this->chatHistory = new InMemoryChatHistory();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): AgentState
    {
        $this->chatHistory = $chatHistory;
        return $this;
    }

    public function incrementToolAttempt(string $toolName): void
    {
        $attempts = $this->get('tool_attempts', []);
        $attempts[$toolName] = ($attempts[$toolName] ?? 0) + 1;
        $this->set('tool_attempts', $attempts);
    }

    public function getToolAttempts(string $toolName): int
    {
        $attempts = $this->get('tool_attempts', []);
        return $attempts[$toolName] ?? 0;
    }

    public function resetToolAttempts(): void
    {
        $this->set('tool_attempts', []);
    }
}
