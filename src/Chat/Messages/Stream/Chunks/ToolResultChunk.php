<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

use NeuronAI\Tools\ToolCall;

class ToolResultChunk extends StreamChunk
{
    public function __construct(
        public readonly ToolCall $tool,
    ) {
        parent::__construct();
    }

    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'tools' => $this->tool->jsonSerialize(),
        ];
    }
}
