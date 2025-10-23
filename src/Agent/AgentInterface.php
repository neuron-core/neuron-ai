<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\ChatHistoryInterface;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\Toolkits\ToolkitInterface;
use NeuronAI\Workflow\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

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
     * Register middleware for the agent's workflow.
     *
     * @param class-string<Event>|WorkflowMiddleware $eventClass Event class or global middleware
     * @param WorkflowMiddleware|WorkflowMiddleware[]|null $middleware Middleware instance(s)
     */
    public function middleware(string|WorkflowMiddleware $eventClass, WorkflowMiddleware|array|null $middleware = null): AgentInterface;

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
