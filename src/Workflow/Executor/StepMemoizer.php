<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Executor;

use Closure;
use NeuronAI\Exceptions\WorkflowRefusedException;

/** Durable memoization bound to one node-execution step, and the lease renewal of its run. */
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

    /**
     * @throws WorkflowRefusedException
     */
    public function heartbeat(): void
    {
        $this->store->heartbeat();
    }
}
