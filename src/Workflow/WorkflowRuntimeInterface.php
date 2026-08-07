<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The workflow as the execution machinery sees it (the engine-facing contract).
 *
 * Executors type against THIS interface, never against {@see WorkflowInterface}:
 * these are the methods an engine must call to traverse a workflow correctly —
 * they are collaboration points of the runtime, not operations an application
 * performs on a workflow. Keeping them off the user contract means application
 * code cannot call machinery methods by accident, and implementing the user
 * contract never requires implementing engine plumbing.
 *
 * `Workflow` implements both interfaces; the executor receives it through this
 * narrower view.
 */
interface WorkflowRuntimeInterface
{
    /**
     * The event traversal begins from. Rebuilt fresh on every run (replay-by-
     * rerun): start events are never recalled from persistence.
     */
    public function getStartEvent(): Event;

    /**
     * The current run state. The engine reads it at traversal boundaries and
     * after interrupts.
     */
    public function resolveState(): WorkflowState;

    /**
     * Follow a completed step's persisted state so a replayed (cached) step
     * restores exactly what it recorded.
     */
    public function setState(WorkflowState $state): WorkflowInterface;

    public function getNodeForEvent(string $eventClass): NodeInterface;

    /**
     * @return array<string, NodeInterface>
     */
    public function getEventNodeMap(): array;

    /**
     * The middleware to wrap around one node's execution, resolved with
     * subclass-aware matching.
     *
     * @return array<int, Middleware\WorkflowMiddleware>
     */
    public function getMiddlewareForNode(NodeInterface $node): array;

    /**
     * The unique identifier of this workflow run — the persistence namespace
     * the engine stores steps under.
     */
    public function getRunId(): string;

    /**
     * The PSR-14 dispatcher the engine emits observability events through.
     */
    public function getEventDispatcher(): EventDispatcherInterface;

    /**
     * Restore an event recalled from a persisted step before it re-enters
     * traversal. Persistence strips transient capability from events (objects
     * that cannot or must not serialize — e.g. the agent's live tools); this is
     * the symmetric seam where the workflow puts it back. The engine calls it
     * on every step-result event, live or recalled — implementations must be
     * idempotent (restore only what is missing). The default restores nothing.
     */
    public function restoreEventNode(Event $event): Event;
}
