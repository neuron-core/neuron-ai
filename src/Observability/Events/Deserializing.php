<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class Deserializing extends ObservabilityEvent
{
    public function __construct(public string $class)
    {
    }

    public function name(): string
    {
        return 'structured-deserializing';
    }
}
