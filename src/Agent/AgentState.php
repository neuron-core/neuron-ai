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
        return $this->chatHistory ??= new InMemoryChatHistory();
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): AgentState
    {
        $this->chatHistory = $chatHistory;
        return $this;
    }

    public function incrementToolRun(string $toolName): void
    {
        $attempts = $this->get('__tool_runs', []);
        $attempts[$toolName] = ($attempts[$toolName] ?? 0) + 1;
        $this->set('__tool_runs', $attempts);
    }

    public function getToolRuns(string $toolName): int
    {
        $attempts = $this->get('__tool_runs', []);
        return $attempts[$toolName] ?? 0;
    }

    public function resetToolRuns(): void
    {
        $this->delete('__tool_runs');
    }

    public function addStep(Message $message): void
    {
        $steps = $this->get('__steps', []);
        $steps[] = $message;
        $this->set('__steps', $steps);
    }

    /**
     * @return Message[]
     */
    public function getSteps(): array
    {
        return $this->get('__steps', []);
    }

    public function resetSteps(): void
    {
        $this->delete('__steps');
    }
}
