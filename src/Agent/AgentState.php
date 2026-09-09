<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\WorkflowState;

use function array_map;
use function count;
use function end;
use function get_object_vars;

/**
 * Extends WorkflowState with agent-specific state management.
 *
 * The chat history is a runtime service injected into agent nodes, never part
 * of this state. The `__steps` accumulator is transient: excluded from durable
 * snapshots, it reports the messages of the current execution cycle only.
 */
class AgentState extends WorkflowState
{
    public InferenceRequest $request;

    public function __clone(): void
    {
        parent::__clone();

        if (isset($this->request)) {
            $this->request = clone $this->request;
        }
    }

    // Exclude the transient `__steps` accumulator from durable snapshots.
    public function __serialize(): array
    {
        $properties = get_object_vars($this);
        unset($properties['data']['__steps']);
        unset($properties['data']['__tool_runs']);

        return $properties;
    }

    public function __unserialize(array $properties): void
    {
        foreach ($properties as $name => $value) {
            $this->{$name} = $value;
        }
    }

    public function incrementToolRun(string $toolName): void
    {
        $attempts = $this->get('__tool_runs', []);
        $attempts[$toolName] = ($attempts[$toolName] ?? 0) + 1;
        $this->set('__tool_runs', $attempts);
    }

    public function getToolRuns(?string $toolName = null): int
    {
        $attempts = $this->get('__tool_runs', []);

        if ($toolName === null) {
            return $attempts;
        }

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
     * replace-last rule in AbstractChatHistory.
     *
     * @return array<int, string|null>
     */
    protected function callIds(ToolCallMessage $message): array
    {
        return array_map(
            static fn (ToolCall $tool): ?string => $tool->getCallId(),
            $message->getToolCalls()
        );
    }

    /**
     * The messages generated during the current execution cycle.
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

    /**
     * The final assistant message of the run — the message carried by the stored
     * provider response. Null only when the run paused before any inference
     * produced a response.
     */
    public function getMessage(): ?Message
    {
        return $this->getResponse()?->message();
    }
}
