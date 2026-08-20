<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use Generator;
use NeuronAI\Agent\Memory\MemoryInterface;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

interface AgentInterface
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
     * A pre-bound history declares thread identity by adoption; an unbound
     * one (constructed without its thread) receives the agent's resolved
     * identity — the framework binds it before first use.
     */
    public function setChatHistory(ChatHistoryInterface $chatHistory): AgentInterface;

    public function getChatHistory(): ChatHistoryInterface;

    public function setMemory(MemoryInterface $memory): AgentInterface;

    public function getMemory(): ?MemoryInterface;

    /**
     * Define the exact, non-empty set of conversation threads memory may
     * recall from. Without this configuration, recall uses the current thread.
     *
     * @param string[] $threadIds
     */
    public function setMemoryRecallThreadIds(array $threadIds): AgentInterface;

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
    public function stream(Message|array $messages = [], ?StreamAdapterInterface $adapter = null): Generator;

    /**
     * @param Message|Message[] $messages
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1): mixed;

    /**
     * Continue a suspended run by delivering the inbound payload — the single
     * continuation verb (a new turn is a new run; an answer is a resume).
     *
     * @param array<string, mixed> $payload
     * @param string|null $expectedRunId Optional generation fence supplied by a coordinator.
     */
    public function resume(
        array $payload = [],
        bool $timedOut = false,
        ?string $expectedRunId = null,
    ): AgentState;
}
