<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream;

class ToolCallChunk
{
    public function __construct(public readonly array $tools)
    {
    }
}
