<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
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
     * @param ToolInterface|ToolInterface[]|ToolkitInterface $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface;

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array;

    /**
     * A pre-bound history explicitly selects the conversation between
     * interactions; an unbound one receives the current thread identity.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): AgentInterface;

    public function getChatHistory(): ChatHistoryInterface;

    public function setMemory(MemoryInterface $memory): AgentInterface;

    public function setMemoryUsage(bool $recall = true, bool $remember = true): AgentInterface;

    public function getMemory(): ?MemoryInterface;

    /**
     * Permanently clear both long-term memory and chat history for this conversation.
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
