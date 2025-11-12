<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream;

class ReasoningChunk
{
    public function __construct(
        public readonly string $content
    ) {
    }
}
