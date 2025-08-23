<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

class SchemaGenerated
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public string $class, public array $schema)
    {
    }
}
