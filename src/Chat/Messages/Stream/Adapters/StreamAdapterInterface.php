<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\Stream\Adapters;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Throwable;

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

    /**
     * Protocol suspension sequence, consumed instead of end() when the run
     * pauses for external input.
     *
     * Adapters encode the active requests so the client learns what the run
     * is waiting for, including any termination frames. Return an empty
     * iterable if the protocol cannot express a pause.
     *
     * @param array<int, InterruptRequest> $requests The active requests, keyed by interrupt ID.
     * @return iterable<string>
     */
    public function suspended(array $requests): iterable;

    /**
     * Protocol failure sequence, consumed instead of end() when streaming fails.
     *
     * Adapters encode the original error for their protocol, including any
     * termination frames. Return an empty iterable if no failure output is needed.
     *
     * @return iterable<string>
     */
    public function error(Throwable $error): iterable;
}
