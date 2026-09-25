<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

class MiddlewareStart extends ObservabilityEvent
{
    public function __construct(
        public WorkflowMiddleware $middleware,
        public Event $event,
        public string $phase = 'before'
    ) {
    }

    public function name(): string
    {
        return "middleware-{$this->phase}-start";
    }

    public function toArray(): array
    {
        return [
            'class' => $this->middleware::class,
            'node-event' => $this->event::class,
        ];
    }
}
