<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Tools\ToolInterface;

class ToolCalled
{
    public function __construct(public ToolInterface $tool)
    {
    }
}
