<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Workflow\Middleware\WorkflowMiddleware;

class MiddlewareEnd
{
    public function __construct(public WorkflowMiddleware $middleware)
    {
    }
}
