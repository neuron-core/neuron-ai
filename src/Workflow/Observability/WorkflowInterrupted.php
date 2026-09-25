<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Dispatched when a run suspends waiting for external input (approval,
 * awaited event, timer). Distinct from WorkflowError: an interruption is a
 * scheduled pause, not a failure. Carries the complete state and its single
 * current request; deferred branch requests are reported when they become current.
 */
class WorkflowInterrupted extends ObservabilityEvent
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
            'interrupt' => $this->state->getInterruptRequest()?->jsonSerialize(),
        ];
    }
}
