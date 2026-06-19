<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\WorkflowState;

class WorkflowEnd
{
    public function __construct(public WorkflowState $state)
    {
    }
}
