<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Events;

class TextChunk extends StreamChunk
{
    public function __construct(
        string $id,
        string $messageId,
        public readonly string $content,
    ) {
        parent::__construct($id, $messageId);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'messageId' => $this->messageId,
            'content' => $this->content,
        ];
    }
}
