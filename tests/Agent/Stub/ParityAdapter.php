<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Stub;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use Throwable;
use function count;

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

    public function error(Throwable $error): iterable
    {
        yield 'error:' . $error->getMessage() . "\n";
    }

    public function suspended(array $requests): iterable
    {
        yield 'suspended:' . count($requests) . "\n";
    }

    public function end(): iterable
    {
        yield "end\n";
    }
}
