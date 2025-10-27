<?php

declare(strict_types=1);

namespace NeuronAI\Workflow;

trait ResolveState
{
    public function setState(WorkflowState $state): self
    {
        $this->state = $state;
        return $this;
    }

    protected function state(): WorkflowState
    {
        return new WorkflowState();
    }

    /**
     * Get the current instance of the chat history.
     */
    public function resolveState(): WorkflowState
    {
        return $this->state ?? $this->state = $this->state();
    }
}
