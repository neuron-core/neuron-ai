<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\WorkflowState;

use function array_map;
use function end;
use function count;

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

    /**
     * @param string $toolName The tool name for regular tools, or a custom run key
     *                         when the tool implements HasRunKey.
     */
    public function incrementToolRun(string $toolName): void
    {
        $attempts = $this->get('__tool_runs', []);
        $attempts[$toolName] = ($attempts[$toolName] ?? 0) + 1;
        $this->set('__tool_runs', $attempts);
    }

    /**
     * @param string $toolName The tool name for regular tools, or a custom run key
     *                         when the tool implements HasRunKey.
     */
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

        if (
            $message instanceof ToolCallMessage
            && $steps !== []
            && end($steps) instanceof ToolCallMessage
            && $this->callIds($message) === $this->callIds(end($steps))
        ) {
            $steps[count($steps) - 1] = $message;
        } else {
            $steps[] = $message;
        }

        $this->set('__steps', $steps);
    }

    /**
     * The ordered list of tool callIds on a ToolCallMessage, used to detect a re-write
     * of an already-recorded step (an approval-state update on replay). Mirrors the
     * replace-last rule in AbstractChatHistory (ADR 0003).
     *
     * @return array<int, string|null>
     */
    protected function callIds(ToolCallMessage $message): array
    {
        return array_map(
            static fn (ToolInterface $tool): ?string => $tool->getCallId(),
            $message->getTools()
        );
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

    public function setResponse(ProviderResponse $response): AgentState
    {
        $this->set('__provider_response', $response);
        return $this;
    }

    public function getResponse(): ?ProviderResponse
    {
        return $this->get('__provider_response');
    }
}
