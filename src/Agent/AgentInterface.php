<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Workflow\ExecutionContext;
use Generator;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ProviderToolInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\WorkflowInterface;

/** @extends WorkflowInterface<AgentState> */
interface AgentInterface extends WorkflowInterface
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface;

    public function getProvider(ExecutionContext $context): AIProviderInterface;

    public function setInstructions(SystemMessage|string $instructions): AgentInterface;

    public function getInstructions(ExecutionContext $context): SystemMessage;

    /**
     * Replace all tools, including the defaults declared by the agent.
     * An empty array disables all default and previously added tools.
     *
     * @param array<ToolInterface|ToolkitInterface|ProviderToolInterface> $tools
     */
    public function setTools(array $tools): AgentInterface;

    /**
     * @param ToolInterface|ToolInterface[]|ToolkitInterface $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface;

    /**
     * @return ToolInterface[]
     */
    public function getTools(ExecutionContext $context): array;

    /**
     * A pre-bound history explicitly selects the conversation between
     * interactions; an unbound one receives the current thread identity.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): AgentInterface;

    public function getChatHistory(): ChatHistoryInterface;

    /**
     * Clear chat history and abandon the pending execution for this conversation.
     */
    public function resetConversation(): AgentInterface;

    /**
     * The agent's thread identity — the conversation this run belongs to and
     * the run's declared workflow ID — or null when the run is not
     * findable by its thread.
     */
    public function getThreadId(): ?string;

    /**
     * @param Message|Message[] $messages
     */
    public function chat(Message|array $messages = [], ?string $idempotencyKey = null): AgentState;

    /**
     * @param Message|Message[] $messages
     * @return Generator<int, object|string, mixed, AgentState>
     */
    public function stream(Message|array $messages = [], ?string $idempotencyKey = null): Generator;

    /**
     * @param Message|Message[] $messages
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?string $idempotencyKey = null): mixed;
}
