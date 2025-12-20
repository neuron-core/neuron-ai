<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

class AudioChunk extends StreamChunk
{
    public function __construct(
        string $messageId,
        public readonly string $content,
    ) {
        parent::__construct($messageId);
    }

    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'content' => $this->content,
        ];
    }
}
