<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Chat\Messages\Stream\Adapters\StreamAdapterInterface;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;

/**
 * Deterministic protocol adapter: unlike AGUIAdapter it generates no random
 * ids, so two independent instances produce identical output for the same
 * item sequence — which is what byte-parity needs.
 */
class ParityAdapter implements StreamAdapterInterface
{
    public function start(): iterable
    {
        yield "start\n";
    }

    public function transform(object $chunk): iterable
    {
        if ($chunk instanceof TextChunk) {
            yield 'text:' . $chunk->content . "\n";
        }
    }

    public function end(): iterable
    {
        yield "end\n";
    }
}
