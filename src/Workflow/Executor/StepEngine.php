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
     * - If the step was interrupted and has resume data: pass it to $fn as a parameter.
     * - If no cached result: execute $fn, store the result, return it.
     *
     * The callable receives (?InterruptRequest $resumeRequest) as its parameter.
     * Must return a StepResult carrying event and state.
     *
     * @param callable(?InterruptRequest): StepResult $fn
     */
    public function runStep(string $stepId, callable $fn): StepResult;

    /**
     * Record an interrupt at this step position.
     *
     * Called when a node throws WorkflowInterrupt.
     */
    public function interruptStep(string $stepId, InterruptRequest $request): void;

    /**
     * Inject the user's resume decision before traversal begins.
     */
    public function setResumeRequest(InterruptRequest $request): void;

    /**
     * Prepare for a new execute() call. Called once at the start of each traversal.
     */
    public function prepareExecution(): void;

    /**
     * Clean up step data after a workflow completes successfully.
     */
    public function deleteSteps(): void;
}
