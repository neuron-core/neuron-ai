<?php

declare(strict_types=1);

namespace NeuronAI\Stream\Adapters;

interface StreamAdapterInterface
{
    /**
     * Transform a Neuron chunk into protocol-specific output.
     *
     * @param object $chunk Any Neuron chunk (TextChunk, ToolCallChunk, etc.)
     * @return iterable<string> One or more output lines/messages
     */
    public function transform(object $chunk): iterable;

    /**
     * Get HTTP headers for this protocol.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array;

    /**
     * Protocol initialization sequence (optional).
     *
     * @return iterable<string>
     */
    public function start(): iterable;

    /**
     * Protocol termination sequence (optional).
     *
     * @return iterable<string>
     */
    public function end(): iterable;
}
