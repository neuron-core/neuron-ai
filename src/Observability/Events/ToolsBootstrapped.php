<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Tools\ToolInterface;

class ToolsBootstrapped
{
    /**
     * @param ToolInterface[] $tools
     * @param string[] $guidelines
     */
    public function __construct(public array $tools, public array $guidelines = [])
    {
    }
}
