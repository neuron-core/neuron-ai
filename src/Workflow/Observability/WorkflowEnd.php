<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

class WorkflowEnd extends ObservabilityEvent
{
    public function __construct(public WorkflowState $state)
    {
    }

    public function toArray(): array
    {
        return [
            'workflowId' => $this->state->getWorkflowId(),
            'runId' => $this->state->getRunId(),
            'executionAttempt' => $this->state->getExecutionAttempt(),
            'status' => $this->state->getStatus()->value,
            'state' => $this->state->all(),
        ];
    }
}
