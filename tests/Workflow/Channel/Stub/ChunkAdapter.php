<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Channel\Stub;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Tests\Workflow\Executor\Stub\ChunkEvent;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

/**
 * Minimal stateless adapter: one ChunkEvent becomes one protocol event and
 * nothing else is framed, so channel tests can assert on payload order alone.
 */
class ChunkAdapter implements StreamAdapterInterface
{
    public function reset(): void
    {
    }

    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof ChunkEvent) {
            yield new ProtocolEvent('chunk', ['payload' => $chunk->payload]);
        }
    }

    public function start(): iterable
    {
        return [];
    }

    public function end(): iterable
    {
        return [];
    }

    public function suspended(InterruptRequest $request): iterable
    {
        return [];
    }

    public function error(Throwable $error): iterable
    {
        return [];
    }
}
