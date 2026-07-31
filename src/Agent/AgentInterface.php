<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

interface AgentInterface
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface;

    public function resolveProvider(): AIProviderInterface;

    public function setInstructions(SystemMessage|string $instructions): AgentInterface;

    public function resolveInstructions(): SystemMessage;

    /**
     * @param ToolInterface|ToolInterface[]|ToolkitInterface $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface;

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array;

    public function setChatHistory(AbstractChatHistory $chatHistory): AgentInterface;

    public function getChatHistory(): ChatHistoryInterface;

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start; a payload to resume.
     */
    public function chat(Message|array $messages = [], ?array $payload = null, bool $timedOut = false): AgentHandler;

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start; a payload to resume.
     */
    public function stream(Message|array $messages = [], ?array $payload = null, bool $timedOut = false): AgentHandler;

    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed>|null $payload Null to start; a payload to resume.
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?array $payload = null, bool $timedOut = false): mixed;
}
