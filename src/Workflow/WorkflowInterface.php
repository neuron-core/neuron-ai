<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\WorkflowExecutorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

interface WorkflowInterface
{
    /**
     * @deprecated Use init() instead
     */
    public function start(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface;

    public function init(?InterruptRequest $resumeRequest = null): WorkflowHandlerInterface;

    public function setPersistence(PersistenceInterface $persistence, ?string $resumeToken = null): WorkflowInterface;

    public function setStartEvent(Event $event): WorkflowInterface;

    public function setState(WorkflowState $state): WorkflowInterface;

    public function resolveState(): WorkflowState;

    public function addNode(NodeInterface $node): Workflow;

    /**
     * @param NodeInterface[] $nodes
     */
    public function addNodes(array $nodes): Workflow;

    public function getNodeForEvent(string $eventClass): ?NodeInterface;

    public function hasNodeForEvent(string $eventClass): bool;

    public function addGlobalMiddleware(WorkflowMiddleware|array $middleware): WorkflowInterface;

    public function addMiddleware(string|array $node, WorkflowMiddleware|array $middleware): WorkflowInterface;

    public function getMiddlewareForNode(NodeInterface $node): array;

    /**
     * @return array<string, NodeInterface>
     */
    public function getEventNodeMap(): array;

    public function getWorkflowId(): string;

    public function getResumeToken(): string;

    public function export(): string;

    public function observe(ObserverInterface $observer): WorkflowInterface;

    public function setExecutor(WorkflowExecutorInterface $executor): WorkflowInterface;
}
