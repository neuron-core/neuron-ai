<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

interface WorkflowInterface
{
    public function bootstrap(): static;

    public function run(?InterruptRequest $interrupt = null): WorkflowState;

    /**
     * Execute the workflow, yielding events in real time.
     *
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function events(?InterruptRequest $interrupt = null): Generator;

    public function getStartEvent(): Event;

    public function setStartEvent(Event $event): WorkflowInterface;

    public function setState(WorkflowState $state): WorkflowInterface;

    public function resolveState(): WorkflowState;

    public function addNode(NodeInterface $node): Workflow;

    /**
     * @param NodeInterface[] $nodes
     */
    public function addNodes(array $nodes): Workflow;

    public function getNodeForEvent(string $eventClass): NodeInterface;

    public function addGlobalMiddleware(WorkflowMiddleware|array $middleware): WorkflowInterface;

    public function addMiddleware(string|array $node, WorkflowMiddleware|array $middleware): WorkflowInterface;

    public function getMiddlewareForNode(NodeInterface $node): array;

    /**
     * @return array<string, NodeInterface>
     */
    public function getEventNodeMap(): array;

    public function getWorkflowId(): string;

    public function getResumeToken(): string;

    public function observe(ObserverInterface $observer): WorkflowInterface;

    public function export(): string;
}
