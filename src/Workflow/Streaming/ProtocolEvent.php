<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming;

use JsonSerializable;

/**
 * One wire-level event of a UI protocol, as produced by a stream adapter.
 * Transport-neutral: an SSE encoder frames it, a broadcast channel names it
 * by type and ships the data. The payload holds only JSON-serializable values.
 */
final class ProtocolEvent implements JsonSerializable
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $type,
        public readonly array $data = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return ['type' => $this->type, ...$this->data];
    }
}
