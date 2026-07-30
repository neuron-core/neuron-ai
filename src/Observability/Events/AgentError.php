<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use Throwable;

class AgentError extends ObservabilityEvent
{
    public function __construct(
        public Throwable $exception,
        public bool $unhandled = true
    ) {
    }

    public function name(): string
    {
        return 'error';
    }
}
