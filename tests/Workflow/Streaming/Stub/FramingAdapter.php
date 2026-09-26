<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Streaming\Stub;

use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

/**
 * Frames every stage of a segment, turning one chunk into two protocol
 * events, so tests can follow how the segment numbers its output.
 */
class FramingAdapter implements StreamAdapterInterface
{
    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof ChunkEvent) {
            yield new ProtocolEvent('chunk-start', ['payload' => $chunk->payload]);
            yield new ProtocolEvent('chunk-end', ['payload' => $chunk->payload]);
        }
    }

    public function start(): iterable
    {
        return [new ProtocolEvent('start')];
    }

    public function end(): iterable
    {
        return [new ProtocolEvent('end')];
    }

    public function interrupt(InterruptRequest $request): iterable
    {
        return [new ProtocolEvent('paused', ['message' => $request->getMessage()])];
    }

    public function error(Throwable $error): iterable
    {
        return [new ProtocolEvent('error', ['message' => $error->getMessage()])];
    }
}
