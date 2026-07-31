<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Observability\ObserverInterface;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use Psr\EventDispatcher\EventDispatcherInterface;

interface WorkflowInterface
{
    /**
     * Start the workflow to completion (or replay cached steps after a crash).
     * Delivers no resume payload — use {@see resume()} for that.
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

    /**
     * The unique identifier of this workflow run — also the resume handle: pass
     * it back to the constructor to reattach to a suspended run.
     */
    public function getRunId(): string;

    /**
     * @deprecated Use subscribe() with a PSR-14 listener instead. Will be
     *             removed in the next major version.
     */
    public function observe(ObserverInterface $observer): WorkflowInterface;

    /**
     * Register a PSR-14 listener for a specific event class.
     *
     * @param class-string $eventClass
     */
    public function subscribe(string $eventClass, callable $listener): WorkflowInterface;

    /**
     * Forward this workflow's events to an external PSR-14 dispatcher.
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): WorkflowInterface;

    /**
     * The PSR-14 dispatcher owned by this workflow instance.
     */
    public function getEventDispatcher(): EventDispatcherInterface;

    public function export(): string;
}
