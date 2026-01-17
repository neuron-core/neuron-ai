<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

class MiddlewareStart
{
    public function __construct(public WorkflowMiddleware $middleware, public Event $event)
    {
    }
}
