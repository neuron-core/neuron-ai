<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

class BranchEnd
{
    public function __construct(public readonly string $branchId)
    {
    }
}
