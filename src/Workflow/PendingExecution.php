<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use Generator;
use NeuronAI\Workflow\Executor\ExecutionRequest;

/**
 * @template-covariant TState of WorkflowState = WorkflowState
 */
final class PendingExecution
{
    /** @param WorkflowInterface<TState> $workflow */
    public function __construct(
        protected readonly WorkflowInterface $workflow,
        protected readonly ExecutionRequest $request,
    ) {
    }

    /** @return TState */
    public function run(): WorkflowState
    {
        return $this->workflow->run($this->request);
    }

    /** @return Generator<int, object, mixed, TState> */
    public function events(): Generator
    {
        return $this->workflow->events($this->request);
    }
}
