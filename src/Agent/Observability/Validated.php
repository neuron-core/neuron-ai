<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

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

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'json' => $this->json,
            'violations' => $this->violations,
        ];
    }
}
