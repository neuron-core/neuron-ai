<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Resume\ResumeInput;
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
     * @param non-empty-list<ResumeInput> $inputs
     */
    public function resume(
        array $inputs,
        ?string $expectedRunId = null,
    ): WorkflowState;

    /**
     * Replay an interrupted, failed, or crashed run without delivering a new
     * external input.
     */
    public function recover(
        ?string $expectedRunId = null,
        ?int $expectedExecutionAttempt = null,
    ): WorkflowState;

    /** Conditionally purge a retained completed generation. */
    public function acknowledgeCompletion(string $expectedRunId): void;

    /**
     * Keep the terminal result until the coordinating caller acknowledges it.
     * Disabled by default, so manually managed workflows clean up immediately.
     */
    public function retainCompletionUntilAcknowledged(bool $retain = true): static;

    /**
     * The streaming execution primitive behind run(), resume(), and recover().
     *
     * @param list<ResumeInput> $inputs
     * @param string|null $expectedRunId Optional generation fence supplied by a coordinator.
     * @return Generator<int, Event, mixed, WorkflowState>
     */
    public function events(
        array $inputs = [],
        bool $resuming = false,
        ?string $expectedRunId = null,
        bool $recovering = false,
        ?int $expectedExecutionAttempt = null,
    ): Generator;

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
