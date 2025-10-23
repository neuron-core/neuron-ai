<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Workflow\WorkflowState;

/**
 * Extends WorkflowState with agent-specific state management.
 */
class AgentState extends WorkflowState
{
    public function __construct(
        ChatHistoryInterface $chatHistory = new InMemoryChatHistory(),
        array $data = []
    ) {
        parent::__construct($data);
        $this->set('chat_history', $chatHistory);
        $this->set('tool_attempts', []);
    }

    public function getChatHistory(): ChatHistoryInterface
    {
        return $this->get('chat_history');
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): void
    {
        $this->set('chat_history', $chatHistory);
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
