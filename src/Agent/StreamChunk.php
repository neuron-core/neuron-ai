<?php

declare(strict_types=1);

namespace NeuronAI\Agent;

use NeuronAI\Tools\ToolInterface;

/**
 * Data object representing a text chunk during streaming.
 * This is NOT a workflow Event - it's just data being yielded to the caller.
 */
class StreamChunk
{
    /**
     * @param ToolInterface[] $tools
     */
    public function __construct(
        public readonly ?string $content = null,
        public readonly array $tools = [],
    ) {
    }
}
