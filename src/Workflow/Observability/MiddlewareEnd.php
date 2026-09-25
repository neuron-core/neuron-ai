<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

class MiddlewareEnd extends ObservabilityEvent
{
    public function __construct(
        public WorkflowMiddleware $middleware,
        public string $phase = 'before'
    ) {
    }

    public function name(): string
    {
        return "middleware-{$this->phase}-end";
    }

    public function toArray(): array
    {
        return ['class' => $this->middleware::class];
    }
}
