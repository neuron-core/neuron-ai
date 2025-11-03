<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream;

class ToolResultChunk
{
    public function __construct(public readonly array $tools)
    {
    }
}
