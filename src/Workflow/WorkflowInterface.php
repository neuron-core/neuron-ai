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
 * concrete-class API on {@see Workflow}.
 */
interface WorkflowInterface
{
    /**
     * @return TState
     */
    public function run(?ExecutionRequest $request = null): WorkflowState;

    /**
     * The run holding the workflow ID, or null when none does or the instance is unbound.
     *
     * @phpstan-impure Every call reads the run as persistence holds it now.
     */
    public function inspect(): ?WorkflowRunSnapshot;

    /**
     * @param array<array-key, mixed> $payload
     * @return PendingExecution<TState>
     */
    public function submitInputs(array $payload, ?InputTranslatorInterface $translator = null): PendingExecution;

    /** Conditionally purge a retained completed generation. */
    public function acknowledge(string $expectedRunId): void;

    /**
     * Discard the run holding the workflow ID so a new one can ignite: a
     * paused, failed, or unleased run is deleted; a retained completion or a
     * run under a fresh lease is refused. Optional run and attempt fences
     * protect explicit replacement. False when nothing is in flight.
     */
    public function abandon(?string $expectedRunId = null, ?int $expectedExecutionAttempt = null): bool;

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
     * The instance address, or null until configured or first executed.
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
