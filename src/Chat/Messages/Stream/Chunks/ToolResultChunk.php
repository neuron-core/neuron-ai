<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

use NeuronAI\Tools\ToolInterface;

class ToolResultChunk extends StreamChunk
{
    /**
     * @param ToolInterface $tool
     */
    public function __construct(
        public readonly ToolInterface $tool,
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
