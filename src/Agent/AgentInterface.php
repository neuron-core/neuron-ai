<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;

interface AgentInterface
{
    public function setAiProvider(AIProviderInterface $provider): AgentInterface;

    public function resolveProvider(): AIProviderInterface;

    public function setInstructions(string $instructions): AgentInterface;

    public function instructions(): string;

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
     * Register middleware for a specific node class.
     *
     * @param class-string<NodeInterface> $nodeClass Node class name or array of node classes with middleware
     * @param WorkflowMiddleware|WorkflowMiddleware[] $middleware Middleware instance(s) (required when $nodeClass is a string)
     * @throws WorkflowException
     */
    public function middleware(string $nodeClass, WorkflowMiddleware|array $middleware): self;

    public function observe(\SplObserver $observer, string $event = "*"): self;

    /**
     * @param Message|Message[] $messages
     * @param InterruptRequest|null $interrupt
     */
    public function chat(Message|array $messages = [], ?InterruptRequest $interrupt = null): Message;

    /**
     * @param Message|Message[] $messages
     * @param InterruptRequest|null $resumeRequest
     */
    public function stream(Message|array $messages = [], ?InterruptRequest $resumeRequest = null): \Generator;

    /**
     * @param Message|Message[] $messages
     * @param string|null $class
     * @param int $maxRetries
     * @param InterruptRequest|null $resumeRequest
     * @return mixed
     */
    public function structured(Message|array $messages = [], ?string $class = null, int $maxRetries = 1, ?InterruptRequest $resumeRequest = null): mixed;
}
