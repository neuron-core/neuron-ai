<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Dispatched when a run suspends waiting for external input (approval,
 * awaited event, timer). Distinct from AgentError: an interruption is a
 * scheduled pause, not a failure. Carries the complete state and its single
 * current request; deferred branch requests are reported when they become current.
 */
class WorkflowInterrupted extends ObservabilityEvent
{
    public function __construct(public WorkflowState $state)
    {
    }
}
