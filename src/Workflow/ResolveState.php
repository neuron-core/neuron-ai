<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

/** @template TState of WorkflowState */
trait ResolveState
{
    /** @param TState $state */
    public function setState(WorkflowState $state): static
    {
        $this->state = $state;
        return $this;
    }

    /** @return TState */
    protected function state(): WorkflowState
    {
        return new WorkflowState();
    }

    /**
     * Get the current workflow state, creating the default if none was set.
     *
     * @return TState
     */
    final public function getState(): WorkflowState
    {
        return $this->state ??= $this->state();
    }
}
