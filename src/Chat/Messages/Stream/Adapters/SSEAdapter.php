<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Events\StreamChunk;
use NeuronAI\UniqueIdGenerator;

/**
 * Base adapter for Server-Sent Events (SSE) streaming.
 *
 * Provides common SSE formatting functionality that can be extended
 * by protocol-specific adapters like VercelAIAdapter and AGUIAdapter.
 */
abstract class SSEAdapter implements StreamAdapterInterface
{
    /**
     * Format data as a Server-Sent Event.
     *
     * Converts an array of data into SSE format:
     * data: {"key":"value"}
     *
     * @param array<string, mixed> $data The data to format
     * @return string The formatted SSE string
     */
    protected function sse(array $data): string
    {
        return 'data: ' . \json_encode($data) . "\n\n";
    }

    /**
     * Generate a unique identifier with an optional prefix.
     *
     * @param string $prefix The prefix for the ID
     * @return string The generated ID
     */
    protected function generateId(string $prefix = ''): string
    {
        $id = (string) UniqueIdGenerator::generateId();

        return $prefix !== '' ? $prefix . '_' . $id : $id;
    }

    /**
     * Get the current timestamp in ISO 8601 format.
     *
     * @return string The current timestamp
     */
    protected function timestamp(): string
    {
        return \date('c');
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
