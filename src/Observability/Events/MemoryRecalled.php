<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class MemoryRecalled extends ObservabilityEvent
{
    public function __construct(public int $memoryCount)
    {
    }
}
