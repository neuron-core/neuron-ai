<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Workflow\WorkflowState;

/**
 * Extends WorkflowState with agent-specific state management.
 *
 * Pure serializable data: the chat history is a runtime service injected into
 * agent nodes (see AgentNodeInterface), never carried through the durable
 * state — each per-step snapshot stays O(1) instead of embedding the whole
 * conversation.
 */
class AgentState extends WorkflowState
{
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
