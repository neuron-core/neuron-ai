<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;

class BranchStart extends ObservabilityEvent
{
    public function __construct(string $branchId)
    {
        $this->branchId = $branchId;
    }
}
