<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

abstract class StreamChunk
{
    public function __construct(
        public readonly string $messageId,
    ) {
    }

    /**
     * Convert the chunk to an array representation.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
