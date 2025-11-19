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
        public readonly array $tools,
    ) {
        parent::__construct();
    }

    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'tools' => $this->tools,
        ];
    }
}
