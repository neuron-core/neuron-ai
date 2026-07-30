<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;

class Validated extends ObservabilityEvent
{
    /**
     * @param array<string> $violations
     */
    public function __construct(
        public string $class,
        public string $json,
        public array $violations = []
    ) {
    }

    public function name(): string
    {
        return 'structured-validated';
    }
}
