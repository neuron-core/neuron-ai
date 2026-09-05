<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;

/** Durable memoization bound to one node-execution step. */
final class StepMemoizer
{
    public function __construct(
        protected WorkflowRunStore $store,
        protected string $stepId,
    ) {
    }

    public function memo(string $name, Closure $operation): mixed
    {
        return $this->store->memo($this->stepId, $name, $operation);
    }

    public function get(string $name): mixed
    {
        return $this->store->recallMemo($this->stepId, $name);
    }
}
