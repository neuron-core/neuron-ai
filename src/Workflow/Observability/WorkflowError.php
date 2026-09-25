<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use Throwable;

class WorkflowError extends ObservabilityEvent
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

    public function toArray(): array
    {
        return ['error' => $this->exception->getMessage()];
    }
}
