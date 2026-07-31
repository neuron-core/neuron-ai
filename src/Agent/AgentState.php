<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Workflow\WorkflowState;

use function array_map;
use function count;
use function end;
use function get_object_vars;

/**
 * Extends WorkflowState with agent-specific state management.
 *
 * The chat history is a runtime service injected into agent nodes (see
 * AgentNodeInterface), never carried through the durable state — each per-step
 * snapshot stays O(1) instead of embedding the whole conversation. The `__steps`
 * accumulator is transient for the same reason: it is excluded from
 * serialization, so the final state returned by a run (completed or
 * interrupted) carries the messages of THAT execution cycle only — a resumed
 * run starts its own accumulation.
 */
class AgentState extends WorkflowState
{
    /**
     * Exclude the transient `__steps` accumulator from durable snapshots: it
     * duplicates messages already persisted in the chat history, and it would
     * otherwise grow every snapshot with the conversation.
     */
    public function __serialize(): array
    {
        $properties = get_object_vars($this);
        unset($properties['data']['__steps']);

        return $properties;
    }

    public function __unserialize(array $properties): void
    {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
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
     * The messages generated during the current execution cycle. Transient:
     * not part of durable snapshots, so a resumed run reports only the
     * messages produced since the resume.
     *
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
