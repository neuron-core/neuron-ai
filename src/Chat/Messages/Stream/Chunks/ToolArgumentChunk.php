<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Chunks;

/**
 * A fragment of the tool call arguments the provider is currently streaming.
 *
 * The delta is the raw partial-JSON string as sent by the provider: individual
 * fragments are not valid JSON. Concatenating the deltas of all chunks with the
 * same toolCallId yields the complete arguments JSON. Providers that receive
 * tool arguments in one shot (e.g. Gemini, Ollama) never emit this chunk.
 */
class ToolArgumentChunk extends StreamChunk
{
    public function __construct(
        ?string $messageId,
        public readonly string $toolName,
        public readonly string $delta,
        public readonly ?string $toolCallId = null,
    ) {
        parent::__construct($messageId);
    }

    public function toArray(): array
    {
        return [
            'messageId' => $this->messageId,
            'toolName' => $this->toolName,
            'toolCallId' => $this->toolCallId,
            'delta' => $this->delta,
        ];
    }
}
