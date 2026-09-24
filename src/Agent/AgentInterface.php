<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\History\MessageStoreInterface;
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

    public function getProvider(): AIProviderInterface;

    public function setInstructions(SystemMessage|string $instructions): AgentInterface;

    public function getInstructions(): SystemMessage;

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
    public function getTools(): array;

    /**
     * Where the Agent's conversations are stored. Each execution segment opens
     * its own working history over it.
     */
    public function setMessageStore(MessageStoreInterface $store): AgentInterface;

    /**
     * A fresh view of the conversation on every call. Requires a conversation
     * identity configured or established by execution.
     */
    public function getChatHistory(): ChatHistory;

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
