<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Durable memoizer bound to a single node-execution step.
 *
 * Each memo is persisted as a StepResult under the namespaced step id
 * "{stepId}::{name}", reusing the step engine's existing replay machinery:
 * the first call runs the operation and persists the value; on replay the
 * cached value is returned without re-running the operation.
 *
 * An instance is constructed per step by the executor (which knows the
 * current stepId) and threaded into the node via setWorkflowContext().
 */
final class StepMemoizer
{
    public function __construct(
        protected StepEngineInterface $engine,
        protected string $stepId,
    ) {
    }

    public function memo(string $name, Closure $operation): mixed
    {
        $memoStepId = $this->stepId . '::' . $name;

        $result = $this->engine->runStep(
            $memoStepId,
            fn (?InterruptRequest $resume): StepResult => new StepResult(
                stepId: $memoStepId,
                output: $operation(),
            ),
        );

        return $result->getOutput();
    }

    /**
     * Recall a previously memoized value WITHOUT running anything.
     *
     * Returns the recorded output when a completed memo step exists for this
     * name (typically a prior run's recovery), or null otherwise. Call it before
     * the matching memo() so a fresh run sees nothing and executes the work.
     *
     * This is the read-only counterpart to memo(): it lets a node skip
     * non-replayable work whose terminal value was already persisted — e.g. a
     * StreamingNode recalling a completed provider response instead of re-opening
     * a non-resumable stream. memo() handles the write side.
     */
    public function get(string $name): mixed
    {
        $cached = $this->engine->getStep($this->stepId . '::' . $name);

        return $cached?->getOutput();
    }
}
