<?php

declare(strict_types=1);

namespace NeuronAI;

use GuzzleHttp\Promise\PromiseInterface;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;

interface AgentInterface extends \SplSubject
{
    public function withProvider(AIProviderInterface $provider): AgentInterface;

    public function resolveProvider(): AIProviderInterface;

    public function withInstructions(string $instructions): AgentInterface;

    public function instructions(): string;

    /**
     * @param ToolInterface|ToolInterface[]|ToolkitInterface $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface;

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array;

    public function withChatHistory(AbstractChatHistory $chatHistory): AgentInterface;

    public function resolveChatHistory(): ChatHistoryInterface;

    public function observe(\SplObserver $observer, string $event = "*"): self;

    /**
     * @param Message|Message[] $messages
     */
    public function chat(Message|array $messages): Message;

    /**
     * @param Message|Message[] $messages
     */
    public function chatAsync(Message|array $messages): PromiseInterface;

    /**
     * @param Message|Message[] $messages
     */
    public function stream(Message|array $messages): \Generator;

    /**
     * @param Message|Message[] $messages
     */
    public function structured(Message|array $messages, ?string $class = null, int $maxRetries = 1): mixed;
}
