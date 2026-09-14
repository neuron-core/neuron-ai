<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Adapter;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

interface StreamAdapterInterface
{
    /**
     * Begin a run segment. The Workflow calls it before start() on every
     * segment, so one instance can serve a suspension and its continuation
     * in the same process: drop the previous segment's stream state, keep
     * the seeded protocol identity and snapshot.
     */
    public function reset(): void;

    /**
     * Transform a Neuron chunk into protocol events.
     *
     * @param object $chunk Neuron chunk (TextChunk, ToolCallChunk, etc.) or custom objects
     * @return iterable<ProtocolEvent> Zero or more events
     */
    public function transform(object $chunk): iterable;

    /**
     * Protocol initialization sequence (optional).
     *
     * @return iterable<ProtocolEvent>
     */
    public function start(): iterable;

    /**
     * Protocol termination sequence (optional).
     *
     * @return iterable<ProtocolEvent>
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
     * @return iterable<ProtocolEvent>
     */
    public function suspended(array $requests): iterable;

    /**
     * Protocol failure sequence, consumed instead of end() when streaming fails.
     *
     * Adapters encode the original error for their protocol, including any
     * termination frames. Return an empty iterable if no failure output is needed.
     *
     * @return iterable<ProtocolEvent>
     */
    public function error(Throwable $error): iterable;
}
