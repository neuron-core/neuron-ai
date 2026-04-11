<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\WorkflowState;

class WorkflowNodeStart
{
    public function __construct(
        public string $node,
        public WorkflowState $state,
    ) {
    }
}
