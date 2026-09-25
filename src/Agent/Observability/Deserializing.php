<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

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

    public function toArray(): array
    {
        return ['class' => $this->class];
    }
}
