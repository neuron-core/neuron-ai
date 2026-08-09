<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Workflow\Persistence\PersistenceInterface;

/**
 * Durable memoizer bound to a single node-execution step.
 *
 * Each memo is persisted as a StepResult under the namespaced step id
 * "{stepId}::{name}": the first call runs the operation and persists the
 * value; on replay the cached value is returned without re-running the
 * operation.
 *
 * An instance is constructed per step by the executor (which knows the
 * current stepId) and threaded into the node via setWorkflowContext().
 */
final class StepMemoizer
{
    public function __construct(
        protected PersistenceInterface $persistence,
        protected string $runId,
        protected string $stepId,
    ) {
    }

    public function memo(string $name, Closure $operation): mixed
    {
        $memoStepId = $this->stepId . '::' . $name;

        $cached = $this->loadCompleted($memoStepId);
        if ($cached instanceof StepResult) {
            return $cached->getOutput();
        }

        $value = $operation();

        $this->persistence->save($this->runId, $memoStepId, new StepResult(
            stepId: $memoStepId,
            output: $value,
        ));

        return $value;
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
     * streaming node recalling a completed provider response instead of
     * re-opening a non-resumable stream. memo() handles the write side.
     */
    public function get(string $name): mixed
    {
        return $this->loadCompleted($this->stepId . '::' . $name)?->getOutput();
    }

    /**
     * A memo is recallable only when it completed: interrupted or failed
     * markers never replay from cache.
     */
    protected function loadCompleted(string $stepId): ?StepResult
    {
        $cached = $this->persistence->load($this->runId, $stepId);

        if ($cached instanceof StepResult && !$cached->isInterrupted() && !$cached->isFailed()) {
            return $cached;
        }

        return null;
    }
}
