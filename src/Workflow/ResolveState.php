<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

use function serialize;
use function unserialize;

/** @template TState of WorkflowState */
trait ResolveState
{
    /** @param TState $state */
    public function setState(WorkflowState $state): static
    {
        $this->initialState = $state;
        return $this;
    }

    /** @return TState */
    protected function state(): WorkflowState
    {
        return new WorkflowState();
    }

    /**
     * Build fresh working state. A configured seed is serializable data; the hook returns a new live instance.
     *
     * @return TState
     */
    final protected function newState(): WorkflowState
    {
        return $this->initialState === null ? $this->state() : unserialize(serialize($this->initialState));
    }
}
