<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\ResumeInput;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @template-covariant TState of WorkflowState
 *
 * The application-facing contract of a workflow. Configuration is
 * concrete-class API on {@see Workflow}; the engine-facing collaboration
 * points live on {@see WorkflowRuntimeInterface}. getWorkflowId() appears on
 * both contracts deliberately: applications hold the continuation handle,
 * the engine reads the same identity as its persistence partition.
 */
interface WorkflowInterface
{
    /**
     * Execute the staged operation, or start/recover a failed run by default.
     *
     * @return TState
     */
    public function run(): WorkflowState;

    /**
     * Stage a continuation; empty inputs recover or process due timers.
     *
     * @param list<ResumeInput> $inputs
     */
    public function resume(
        array $inputs = [],
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): static;

    /**
     * Translate against persisted requests and stage inputs for run() or events().
     *
     * @param array<array-key, mixed> $payload
     */
    public function submitInputs(array $payload, InputTranslatorInterface $translator): static;

    /** @param array<string, mixed> $payload */
    public function signal(string $name, array $payload = []): static;

    /** Conditionally purge a retained completed generation. */
    public function acknowledgeCompletion(string $expectedRunId): void;

    /**
     * Discard the run holding the workflow ID so a new one can ignite: a
     * paused, failed, or unleased run is deleted; a retained completion or a
     * run under a fresh lease is refused. False when nothing is in flight.
     */
    public function abandonRun(?string $expectedRunId = null): bool;

    /**
     * Keep the terminal result until the coordinating caller acknowledges it.
     * Disabled by default, so manually managed workflows clean up immediately.
     */
    public function retainCompletionUntilAcknowledged(bool $retain = true): static;

    /**
     * Stream the staged operation, or start/recover a failed run by default.
     *
     * @return Generator<int, object|string, mixed, TState>
     */
    public function events(): Generator;

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
