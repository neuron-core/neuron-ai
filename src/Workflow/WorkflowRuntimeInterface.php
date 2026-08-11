<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\Ignition;
use NeuronAI\Workflow\Executor\SchedulerInterface;
use NeuronAI\Workflow\Persistence\PersistenceInterface;
use NeuronAI\Workflow\Persistence\Serializer;
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
    public function getState(): WorkflowState;

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
     * the engine stores steps under. Null until the executor's identity phase
     * assigns it (an explicit id, a generated one, or one resolved from the
     * correlation pointer).
     */
    public function getRunId(): ?string;

    /**
     * Adopt the run identity resolved by the executor's identity phase.
     */
    public function adoptRunId(string $runId): void;

    /**
     * The business/correlation key by which this run wants to be addressable
     * (e.g. the Agent's threadId), or null when the workflow declares none —
     * or when the key's source is not yet available (a pending resolver).
     * Read at ignition (to bind the pointer), at continuation (to look the
     * run up), and at completion (to release the pointer).
     */
    public function correlationKey(): ?string;

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

    /**
     * The state store this run's durable records live in (steps, memos, the
     * ignition record). The workflow owns storage configuration; the executor
     * reads it here.
     */
    public function getPersistence(): PersistenceInterface;

    /**
     * The coordinator the executor notifies about suspends, resumes, and
     * completion.
     */
    public function getScheduler(): SchedulerInterface;

    /**
     * The codec this run's durable records are encoded with. The workflow
     * owns the choice (like persistence and scheduler); the executor reads
     * it here and performs all record serialization itself — backends store
     * opaque strings.
     */
    public function getSerializer(): Serializer;

    /**
     * Prepare the definition for traversal (load the event-node map,
     * validate). The executor calls this once per segment, AFTER ignition is
     * resolved — adoption must precede it, because subclasses construct
     * collaborators from ignition context (e.g. thread identity) here.
     */
    public function bootstrap(): void;

    /**
     * Build the run's trigger envelope from the resolved start event and the
     * workflow's context bag. Called by the executor exactly once, on the
     * first segment, to register the run.
     */
    public function makeIgnition(): Ignition;

    /**
     * Offer a persisted trigger envelope for adoption on a continuation
     * segment. A blank process adopts the start event and applies the
     * context; a workflow whose start event is already set keeps its local
     * state (same-instance segment — the two are identical).
     */
    public function adoptIgnition(Ignition $ignition): void;
}
