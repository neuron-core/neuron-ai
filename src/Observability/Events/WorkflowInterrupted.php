<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

/**
 * Dispatched when a run suspends waiting for external input (approval,
 * awaited event, timer). Distinct from AgentError: an interruption is a
 * scheduled pause, not a failure. Carries the complete interrupted state.
 */
class WorkflowInterrupted extends ObservabilityEvent
{
    public function __construct(public WorkflowState $state)
    {
    }
}
