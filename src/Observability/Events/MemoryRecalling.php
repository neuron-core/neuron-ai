<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class MemoryRecalling extends ObservabilityEvent
{
    public function __construct(public int $threadCount)
    {
    }
}
