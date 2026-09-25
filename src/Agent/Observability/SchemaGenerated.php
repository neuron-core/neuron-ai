<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Observability\ObservabilityEvent;

class SchemaGenerated extends ObservabilityEvent
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public string $class, public array $schema)
    {
    }

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'schema' => $this->schema,
        ];
    }
}
