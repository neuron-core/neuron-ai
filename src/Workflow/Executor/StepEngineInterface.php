<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Generator;
use NeuronAI\Workflow\Events\Event;

/**
 * Replay and memoization engine for a durable workflow run.
 *
 * Owns persistence and the per-step replay logic: every executed node is
 * persisted as a StepResult, completed steps are skipped on replay, interrupted
 * steps resume from a staged inbound payload, and failed steps retry.
 *
 * The local {@see LocalStepEngine} is the in-process implementation. The
 * executor depends on this interface — never on a persistence backend or a
 * concrete engine — so the replay strategy is an injectable collaborator built
 * by the host (e.g. {@see \NeuronAI\Workflow\Workflow} from its configured
 * persistence).
 */
interface StepEngineInterface
{
    /**
     * Prepare the engine for a new execution of the given workflow.
     *
     * Stages the inbound resume payload when this run is a deliberate resume of a
     * suspended step. A null $payload means this is a fresh start or a crash-recovery
     * replay (no resume); a non-null $payload (even empty) means resume.
     *
     * @param array<string, mixed>|null $payload The delivered event body, or null when not resuming.
     */
    public function prepareExecution(string $runId, ?array $payload = null, bool $timedOut = false): void;

    /**
     * Run a single step, memoized by step id, yielding its streamed events
     * through in real time and returning the final StepResult.
     *
     * Returns the cached StepResult when a prior generation completed this step
     * (yielding nothing — streamed events are not replayed); resumes an
     * interrupted step with the staged payload; otherwise executes $fn (which
     * runs the node) and persists the outcome. The callable receives the resume
     * payload + timedOut flag (payload is null when not resuming) and returns
     * either the live result directly or a Generator that streams events and
     * returns it.
     *
     * @param callable(array<string,mixed>|null $payload, bool $timedOut): (Generator<int, Event, mixed, StepResult>|StepResult) $fn
     * @return Generator<int, Event, mixed, StepResult>
     */
    public function runStep(string $stepId, callable $fn): Generator;

    /**
     * Return a prior-generation, successfully-completed step result, or null.
     *
     * Read-only counterpart to runStep(): recalls a durable value WITHOUT
     * running anything, used by the memoizer to skip non-replayable work.
     */
    public function getStep(string $stepId): ?StepResult;

    /**
     * Drop all persisted steps for the current workflow (clean completion).
     */
    public function deleteSteps(): void;
}
