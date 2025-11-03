<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream;

use NeuronAI\Tools\ToolInterface;

class TextChunk
{
    public function __construct(
        public readonly string $content,
    ) {
    }
}
