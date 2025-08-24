<?php

namespace NeuronAI;

use NeuronAI\Chat\Messages\ToolCallMessage;

class StreamChunk implements \Stringable
{
    public function __construct(
        public readonly ?string $delta = null,
        public readonly ?ToolCallMessage $toolCall = null
    ){
    }

    public function __toString(): string
    {
        return $this->delta ?? '';
    }
}
