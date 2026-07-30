<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

class WorkflowEnd extends ObservabilityEvent
{
    public function __construct(public WorkflowState $state)
    {
    }
}
