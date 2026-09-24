<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

use NeuronAI\Tools\ToolCall;

class ToolCallChunk extends StreamChunk
{
    /**
     * @param string $messageId The ToolCallMessage holding the call.
     */
    public function __construct(
        string $messageId,
        public readonly ToolCall $tool,
    ) {
        parent::__construct($messageId);
    }

    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'tool' => $this->tool->jsonSerialize(),
        ];
    }
}
