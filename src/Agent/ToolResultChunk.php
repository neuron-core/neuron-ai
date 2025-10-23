<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

class ToolResultChunk
{
    public function __construct(public readonly array $tools)
    {
    }
}
