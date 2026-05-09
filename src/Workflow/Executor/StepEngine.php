<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use NeuronAI\Workflow\Interrupt\InterruptRequest;

interface StepEngine
{
    /**
     * Execute a step with memoization.
     *
     * - If the step has a cached (completed) result: return it without executing $fn.
     * - If the step was interrupted: pass the stored resume request to $fn.
     * - If no cached result: execute $fn, store the result, return it.
     *
     * Implementations may throw to yield control to an external platform
     * (e.g., StepPendingException for HTTP-based durable execution).
     *
     * The callable receives (?InterruptRequest $resumeRequest) as its parameter.
     * Must return a StepResult carrying event and state.
     *
     * @param callable(?InterruptRequest): StepResult $fn
     */
    public function runStep(string $stepId, callable $fn): StepResult;

    /**
     * Prepare for a new execute() call. Called once at the start of each traversal.
     *
     * @param InterruptRequest|null $resume User decision when resuming from an interrupt
     */
    public function prepareExecution(?InterruptRequest $resume = null): void;

    /**
     * Clean up step data after a workflow completes successfully.
     */
    public function deleteSteps(): void;
}
