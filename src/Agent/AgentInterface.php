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
    public function setAiProvider(AIProviderInterface $provider): static;

    public function getProvider(): AIProviderInterface;

    public function setInstructions(SystemMessage|string $instructions): static;

    public function getInstructions(): SystemMessage;

    /**
     * Replace all tools, including the defaults declared by the agent.
     * An empty array disables all default and previously added tools.
     *
     * @param array<ToolInterface|ToolkitInterface|ProviderToolInterface> $tools
     */
    public function setTools(array $tools): static;

    /**
     * @param ToolInterface|ToolkitInterface|ProviderToolInterface|array<ToolInterface|ToolkitInterface|ProviderToolInterface> $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|ProviderToolInterface|array $tools): static;

    /**
     * @return array<ToolInterface|ToolkitInterface|ProviderToolInterface>
     */
    public function getTools(): array;

    /**
     * Where the Agent's conversations are stored. Each execution segment opens
     * its own working history over it.
     */
    public function setMessageStore(MessageStoreInterface $store): static;

    /**
     * A fresh view of the conversation on every call. Requires a conversation
     * identity configured or established by execution.
     */
    public function getChatHistory(): ChatHistory;

    /**
     * Clear chat history and abandon the pending execution for this conversation.
     */
    public function resetConversation(): static;

    /**
     * The conversation identity, or null until configured or first executed.
     */
    public function getThreadId(): ?string;

    public function setThreadId(string $threadId): static;

    /**
     * @param Message|Message[] $messages
     */
    public function chat(Message|array $messages = []): AgentState;

    /**
     * @param Message|Message[] $messages
     * @return Generator<int, object|string, mixed, AgentState>
     */
    public function stream(Message|array $messages = []): Generator;

    /**
     * @param Message|Message[] $messages
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed;
}
