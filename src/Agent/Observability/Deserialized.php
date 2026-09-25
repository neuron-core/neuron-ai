<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Observability\ObservabilityEvent;

class Deserialized extends ObservabilityEvent
{
    public function __construct(public string $class)
    {
    }

    public function name(): string
    {
        return 'structured-deserialized';
    }

    public function toArray(): array
    {
        return ['class' => $this->class];
    }
}
