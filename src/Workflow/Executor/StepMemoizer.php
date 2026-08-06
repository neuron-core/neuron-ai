<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;

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

        $step = $this->engine->runStep(
            $memoStepId,
            fn (?array $payload, bool $timedOut): StepResult => new StepResult(
                stepId: $memoStepId,
                output: $operation(),
            ),
        );

        // runStep is a generator; a memo step streams nothing, so driving it to
        // completion just executes (or recalls) the operation.
        while ($step->valid()) {
            $step->next();
        }

        return $step->getReturn()->getOutput();
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
