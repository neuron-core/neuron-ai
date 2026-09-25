<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Observability;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Tools\ToolCall;

class ToolCalled extends ObservabilityEvent
{
    public function __construct(public ToolCall $tool)
    {
    }

    public function toArray(): array
    {
        return ['tool' => $this->tool->jsonSerialize()];
    }
}
