<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

use NeuronAI\Tools\ToolInterface;

class ToolResultChunk extends StreamChunk
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
            'messageId' => $this->messageId,
            'tools' => $this->tools,
        ];
    }
}
