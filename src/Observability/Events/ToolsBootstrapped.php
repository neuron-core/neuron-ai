<?php

namespace NeuronAI\Observability\Events;

class ToolsBootstrapped
{
    public function __construct(public array $tools)
    {
    }
}
