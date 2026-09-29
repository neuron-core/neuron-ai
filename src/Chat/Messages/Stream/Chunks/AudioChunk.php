<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

class AudioChunk extends StreamChunk
{
    /**
     * @param string $content The base64 of this chunk's bytes: decode each chunk on its own,
     *        as joined base64 strings are not valid base64.
     */
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
