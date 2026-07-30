<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class InstructionsChanged extends ObservabilityEvent
{
    public function __construct(
        public string $previous,
        public string $current
    ) {
    }
}
