<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

interface WorkflowInterface
{
    public function bootstrap(): static;

    /**
     * Start the workflow to completion (or replay cached steps after a crash).
     * Delivers no resume wake — use {@see resume()} for that.
     */
    public function run(): WorkflowState;

    /**
     * Resume a suspended workflow by delivering the inbound payload to the
     * interrupted step.
     *
     * @param array<string, mixed> $payload The delivered event payload (the answer).
     * @param bool                 $timedOut True when the resume was a deadline elapsing.
     */
    public function resume(array $payload = [], bool $timedOut = false): WorkflowState;

    /**
     * The single streaming entry point. Yields events in real time and returns
     * the final state. With no payload it starts/replays; with a payload it resumes
     * the interrupted step (the streaming counterpart of {@see run()} / {@see resume()}).
     *
     * @param array<string, mixed>|null $payload Null to start/replay; the delivered payload to resume.
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function events(?array $payload = null, bool $timedOut = false): Generator;

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

    public function observe(ObserverInterface $observer): WorkflowInterface;

    public function export(): string;
}
