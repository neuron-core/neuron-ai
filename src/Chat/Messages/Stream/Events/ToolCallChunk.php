<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Events;

use NeuronAI\Tools\ToolInterface;

class ToolCallChunk extends StreamChunk
{
    /**
     * @param array<int, ToolInterface> $tools
     */
    public function __construct(
        string $messageId,
        public readonly array $tools,
    ) {
        parent::__construct($messageId);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'messageId' => $this->messageId,
            'tools' => $this->tools,
        ];
    }
}
