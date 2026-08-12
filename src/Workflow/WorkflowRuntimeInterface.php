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
 * The workflow as the execution machinery sees it. Executors type against
 * THIS interface, never against {@see WorkflowInterface}: keeping engine
 * collaboration points off the user contract means application code cannot
 * call machinery methods by accident. `Workflow` implements both.
 */
interface WorkflowRuntimeInterface
{
    /**
     * The event traversal begins from. Rebuilt fresh on every run:
     * start events are never recalled from persistence.
     */
    public function getStartEvent(): Event;

    public function getState(): WorkflowState;

    /**
     * Follow a completed step's persisted state so a replayed (cached) step
     * restores exactly what it recorded.
     */
    public function setState(WorkflowState $state): static;

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
     * The run identifier — the persistence namespace steps live under.
     * Null until the executor's identity phase assigns it.
     */
    public function getRunId(): ?string;

    public function adoptRunId(string $runId): void;

    /**
     * The business key by which this run wants to be addressable (e.g. the
     * Agent's threadId), or null when the workflow declares none.
     */
    public function correlationKey(): ?string;

    public function getEventDispatcher(): EventDispatcherInterface;

    /**
     * Restore the transient capability persistence stripped from a recalled
     * event (objects that must not serialize — e.g. the agent's live tools).
     * The engine calls it exactly at its deserialization sites, never on a
     * live result, so implementations restore unconditionally: an event
     * arriving here always crossed the serializer.
     */
    public function restoreEvent(Event $event): Event;

    /**
     * The state store this run's durable records live in (steps, memos,
     * the ignition record).
     */
    public function getPersistence(): PersistenceInterface;

    /**
     * The coordinator the executor notifies about suspends, resumes, and
     * completion.
     */
    public function getScheduler(): SchedulerInterface;

    /**
     * The codec for this run's durable records. The executor performs all
     * record serialization itself — backends store opaque strings.
     */
    public function getSerializer(): Serializer;

    /**
     * Prepare the definition for traversal. Called once per segment, AFTER
     * ignition is resolved — subclasses construct collaborators from
     * ignition context (e.g. thread identity) here.
     */
    public function bootstrap(): void;

    /**
     * Build the run's trigger envelope. Called by the executor exactly once,
     * on the first segment, to register the run.
     */
    public function makeIgnition(): Ignition;

    /**
     * Offer a persisted trigger envelope for adoption on a continuation
     * segment. A workflow whose start event is already set keeps its local
     * state (same-instance segment — the two are identical).
     */
    public function adoptIgnition(Ignition $ignition): void;
}
