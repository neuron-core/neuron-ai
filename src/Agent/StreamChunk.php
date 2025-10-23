<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

/**
 * Data object representing a text chunk during streaming.
 * This is NOT a workflow Event - it's just data being yielded to the caller.
 */
class StreamChunk
{
    public function __construct(
        public readonly string $content,
    ) {
    }
}
