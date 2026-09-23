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
     * A pre-bound history can identify an unbound Agent. Otherwise its
     * identity must agree with the Agent's fixed conversation identity.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): AgentInterface;

    /** Requires a conversation identity configured or established by execution. */
    public function getChatHistory(): ChatHistoryInterface;

    /**
     * Clear chat history and abandon the pending execution for this conversation.
     */
    public function resetConversation(): AgentInterface;

    /**
     * The conversation identity, or null until configured or first executed.
     */
    public function getThreadId(): ?string;

    public function setThreadId(string $threadId): static;

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
