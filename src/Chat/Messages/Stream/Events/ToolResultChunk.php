<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Events;

use NeuronAI\Tools\ToolInterface;

class ToolResultChunk extends StreamChunk
{
    /**
     * @param string $id
     * @param string $messageId
     * @param array<int, ToolInterface> $tools
     */
    public function __construct(
        string $id,
        string $messageId,
        public readonly array $tools,
    ) {
        parent::__construct($id, $messageId);
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
