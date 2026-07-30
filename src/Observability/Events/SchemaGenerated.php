<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class SchemaGenerated extends ObservabilityEvent
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public string $class, public array $schema)
    {
    }
}
