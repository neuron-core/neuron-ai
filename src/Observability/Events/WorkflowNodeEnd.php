<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

class WorkflowNodeEnd extends ObservabilityEvent
{
    public function __construct(
        public string $node,
        public WorkflowState $state
    ) {
    }
}
