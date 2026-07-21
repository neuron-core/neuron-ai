<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Replay and memoization engine for a durable workflow run.
 *
 * Owns persistence and the per-step replay logic: every executed node is
 * persisted as a StepResult, completed steps are skipped on replay, interrupted
 * steps resume from a stored InterruptRequest, and failed steps retry.
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
     * Advances the generation counter past any persisted steps so prior-run
     * results are treated as cache hits, and stages a resume request when this
     * run is a deliberate resume of a suspended step.
     */
    public function prepareExecution(string $workflowId, ?InterruptRequest $resume = null): void;

    /**
     * Run a single step, memoized by step id.
     *
     * Returns the cached StepResult when a prior generation completed this step;
     * resumes an interrupted step with the staged resume request; otherwise
     * executes $fn (which runs the node) and persists the outcome. The callable
     * receives the resume InterruptRequest or null, and returns the live result.
     */
    public function runStep(string $stepId, callable $fn): StepResult;

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
