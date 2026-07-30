<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Observability\Stubs;

use NeuronAI\Observability\ObservabilityEvent;

class CustomTestEvent extends ObservabilityEvent
{
    public function __construct(public string $value)
    {
    }
}
