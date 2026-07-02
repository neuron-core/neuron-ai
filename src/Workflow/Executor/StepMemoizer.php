<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

/**
 * Durable memoizer bound to a single node-execution step.
 *
 * Each memo is persisted as a StepResult under the namespaced step id
 * "{stepId}::{name}", reusing the LocalStepEngine's existing replay machinery:
 * the first call runs the operation and persists the value; on replay the
 * cached value is returned without re-running the operation.
 *
 * An instance is constructed per step by the executor (which knows the
 * current stepId) and threaded into the node via setWorkflowContext().
 */
final class StepMemoizer
{
    public function __construct(
        protected LocalStepEngine $engine,
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
}
