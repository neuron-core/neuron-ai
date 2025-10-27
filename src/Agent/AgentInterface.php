<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\WorkflowState;

interface AgentInterface
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface;

    public function resolveProvider(): AIProviderInterface;

    public function setInstructions(string $instructions): AgentInterface;

    public function resolveInstructions(): string;

    /**
     * @param ToolInterface|ToolInterface[]|ToolkitInterface $tools
     */
    public function addTool(ToolInterface|ToolkitInterface|array $tools): AgentInterface;

    /**
     * @return ToolInterface[]
     */
    public function getTools(): array;

    public function setChatHistory(AbstractChatHistory $chatHistory): AgentInterface;

    /**
     * @param Message|Message[] $messages
     */
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): Message;

    /**
     * @param Message|Message[] $messages
     */
    public function stream(Message|array $messages = [], ?InterruptRequest $interrupt = null): \Generator;

    /**
     * @param Message|Message[] $messages
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?InterruptRequest $interrupt = null): mixed;
}
