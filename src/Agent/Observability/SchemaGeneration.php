<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Observability\ObservabilityEvent;

class SchemaGeneration extends ObservabilityEvent
{
    public function __construct(public string $class)
    {
    }

    public function toArray(): array
    {
        return ['class' => $this->class];
    }
}
