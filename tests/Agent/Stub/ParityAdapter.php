<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

/**
 * Deterministic protocol adapter: unlike AGUIAdapter it generates no random
 * ids, so two independent instances produce identical output for the same
 * item sequence — which is what parity needs.
 */
class ParityAdapter implements StreamAdapterInterface
{
    public function reset(): void
    {
    }

    public function start(): iterable
    {
        yield new ProtocolEvent('start');
    }

    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield new ProtocolEvent('text', ['content' => $chunk->content]);
        }
    }

    public function error(Throwable $error): iterable
    {
        yield new ProtocolEvent('error', ['message' => $error->getMessage()]);
    }

    public function interrupt(InterruptRequest $request): iterable
    {
        yield new ProtocolEvent('suspended', ['request' => $request->getId()]);
    }

    public function end(): iterable
    {
        yield new ProtocolEvent('end');
    }
}
