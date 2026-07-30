<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class Validating extends ObservabilityEvent
{
    public function __construct(public string $class, public string $json)
    {
    }

    public function name(): string
    {
        return 'structured-validating';
    }
}
