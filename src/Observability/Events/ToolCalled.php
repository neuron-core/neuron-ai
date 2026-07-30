<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Tools\ToolInterface;

class ToolCalled extends ObservabilityEvent
{
    public function __construct(public ToolInterface $tool)
    {
    }
}
