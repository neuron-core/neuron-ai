<?php

declare(strict_types=1);

namespace NeuronAI\Workflow\Streaming\Adapter;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

/**
 * Shapes one segment's stream for a protocol. The Workflow builds an adapter
 * for every segment, so it holds the state of that stream only; a continuation
 * gets a new adapter, seeded with what the client already holds.
 */
interface StreamAdapterInterface
{
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
     * Adapters encode the current request so the client learns what the run
     * is waiting for, including any termination frames. Return an empty
     * iterable if the protocol cannot express a pause.
     *
     * @return iterable<ProtocolEvent>
     */
    public function interrupt(InterruptRequest $request): iterable;

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
