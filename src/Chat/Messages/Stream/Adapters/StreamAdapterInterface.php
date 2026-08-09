<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

interface StreamAdapterInterface
{
    /**
     * Transform a Neuron chunk into protocol-specific output.
     *
     * @param object $chunk Neuron chunk (TextChunk, ToolCallChunk, etc.) or custom objects
     * @return iterable<string> One or more output lines/messages
     */
    public function transform(object $chunk): iterable;

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
