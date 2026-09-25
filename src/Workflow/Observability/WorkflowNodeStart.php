<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\WorkflowState;

class WorkflowNodeStart extends ObservabilityEvent
{
    public function __construct(
        public string $node,
        public WorkflowState $state,
    ) {
    }

    public function toArray(): array
    {
        return ['node' => $this->node];
    }
}
