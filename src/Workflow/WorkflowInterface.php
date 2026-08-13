<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Workflow\Events\Event;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The application-facing contract of a workflow. Configuration is
 * concrete-class API on {@see Workflow}; the engine-facing collaboration
 * points live on {@see WorkflowRuntimeInterface}. getWorkflowId() appears on
 * both contracts deliberately: applications hold the continuation handle,
 * the engine reads the same identity as its persistence partition.
 */
interface WorkflowInterface
{
    /**
     * Ignite a new run under the workflow ID. Never adopts a run already in
     * flight there — that is refused loudly; continue it with {@see resume()}.
     */
    public function run(): WorkflowState;

    /**
     * Continue the run in flight under the workflow ID: replay to its next boundary,
     * delivering the payload only if one is given. A null payload revives
     * without delivering anything — a still-suspended run re-emits its
     * request, a crashed step retries. An empty array is NOT null: it
     * delivers an explicitly empty answer to the waiting step.
     *
     * @param array<string, mixed>|null $payload The delivered answer, or null to deliver nothing.
     * @param bool                      $timedOut True when the resume was a deadline elapsing.
     */
    public function resume(?array $payload = null, bool $timedOut = false): WorkflowState;

    /**
     * The streaming counterpart of {@see run()} / {@see resume()}: yields
     * events in real time and returns the final state. A non-null payload or
     * an explicit $resuming flag makes it a continuation; otherwise it
     * ignites.
     *
     * @param array<string, mixed>|null $payload The delivered answer on a continuation; null otherwise.
     * @param bool $timedOut True when the resume was a deadline elapsing; ignored on ignition.
     * @param bool $resuming True to continue without delivering a payload (revive).
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function events(?array $payload = null, bool $timedOut = false, bool $resuming = false): Generator;

    /**
     * The workflow ID, also the continuation handle: pass it back to the
     * constructor to reattach to a run in flight. Null before the first run
     * segment: identity is assigned by the executor.
     */
    public function getWorkflowId(): ?string;

    /**
     * The current run's generation stamp — observability identity, never the
     * continuation handle. Null before the first run segment.
     */
    public function getRunId(): ?string;

    /**
     * Register a PSR-14 listener for a specific event class.
     *
     * @param class-string $eventClass
     * @param callable(object): void $listener
     */
    public function subscribe(string $eventClass, callable $listener): static;

    /**
     * Forward this workflow's events to an external PSR-14 dispatcher.
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): static;
}
