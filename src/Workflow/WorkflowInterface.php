<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @template-covariant TState of WorkflowState
 *
 * The application-facing contract of a workflow. Configuration is
 * concrete-class API on {@see Workflow}; the engine-facing collaboration
 * points live on {@see WorkflowRuntimeInterface}, implemented by the per-segment runtime.
 */
interface WorkflowInterface
{
    /**
     * @return TState
     */
    public function run(?ExecutionRequest $request = null): WorkflowState;

    /** @param array<array-key, mixed> $payload */
    public function submitInputs(array $payload, InputTranslatorInterface $translator, ?string $idempotencyKey = null, ?string $workflowId = null): ExecutionRequest;

    /** Conditionally purge a retained completed generation. */
    public function acknowledgeCompletion(string $expectedRunId, ?string $workflowId = null): void;

    /**
     * Discard the run holding the workflow ID so a new one can ignite: a
     * paused, failed, or unleased run is deleted; a retained completion or a
     * run under a fresh lease is refused. Optional run and attempt fences
     * protect explicit replacement. False when nothing is in flight.
     */
    public function abandonRun(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null, ?string $workflowId = null): bool;

    /**
     * Keep the terminal result until the coordinating caller acknowledges it.
     * Disabled by default, so manually managed workflows clean up immediately.
     */
    public function retainCompletionUntilAcknowledged(bool $retain = true): static;

    /**
     * @return Generator<int, object, mixed, TState>
     */
    public function events(?ExecutionRequest $request = null): Generator;

    /**
     * The configured/declared default address. Generated execution addresses
     * are returned in state and are never adopted by the definition.
     */
    public function getWorkflowId(): ?string;

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
