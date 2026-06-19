<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

class SchemaGeneration
{
    public function __construct(public string $class)
    {
    }
}
