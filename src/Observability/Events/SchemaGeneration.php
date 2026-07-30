<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class SchemaGeneration extends ObservabilityEvent
{
    public function __construct(public string $class)
    {
    }
}
