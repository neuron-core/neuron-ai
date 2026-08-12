<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Workflow\Events\Event;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The application-facing contract of a workflow. Configuration is
 * concrete-class API on {@see Workflow}; the engine-facing collaboration
 * points live on {@see WorkflowRuntimeInterface}. getRunId() appears on both
 * contracts deliberately: applications hold the resume handle, the engine
 * reads the same identity as its persistence namespace.
 */
interface WorkflowInterface
{
    /**
     * Start the workflow to completion (or replay cached steps after a crash).
     * Delivers no resume payload — use {@see resume()} for that.
     */
    public function run(): WorkflowState;

    /**
     * Resume a suspended workflow by delivering the inbound payload to the
     * interrupted step. Always resumes — an empty payload still targets the
     * interrupted step (unlike {@see events()}, where null means start).
     *
     * @param array<string, mixed> $payload The delivered event payload (the answer).
     * @param bool                 $timedOut True when the resume was a deadline elapsing.
     */
    public function resume(array $payload = [], bool $timedOut = false): WorkflowState;

    /**
     * The streaming counterpart of {@see run()} / {@see resume()}: yields
     * events in real time and returns the final state.
     *
     * @param array<string, mixed>|null $payload Null to start/replay; the delivered payload to resume.
     * @param bool $timedOut True when the resume was a deadline elapsing; ignored on start.
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function events(?array $payload = null, bool $timedOut = false): Generator;

    /**
     * The run identifier, also the resume handle: pass it back to the
     * constructor to reattach to a suspended run. Null before the first run
     * segment: identity is assigned by the executor.
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
