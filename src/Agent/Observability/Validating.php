<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

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

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'json' => $this->json,
        ];
    }
}
