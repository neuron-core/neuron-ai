<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Providers\ProviderResponse;

use function array_map;
use function implode;
use function iterator_to_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/** Builds Server-Sent Events bodies and drains provider stream generators. */
trait ConsumesProviderStreams
{
    /**
     * @param array<int, array<string, mixed>> $events
     */
    protected static function sseBody(array $events, string $lineEnding = "\n"): string
    {
        return implode('', array_map(
            static fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR).$lineEnding.$lineEnding,
            $events,
        ));
    }

    /**
     * @param Generator<int, StreamChunk, mixed, ProviderResponse> $stream
     * @return array{0: StreamChunk[], 1: Message}
     */
    protected function consumeStream(Generator $stream): array
    {
        $chunks = iterator_to_array($stream, false);

        return [$chunks, $stream->getReturn()->message()];
    }

    /**
     * Asserts every chunk is of the given type and returns their contents in order.
     *
     * @param class-string<TextChunk|ReasoningChunk|AudioChunk|ImageChunk> $type
     * @param StreamChunk[] $chunks
     * @return list<string>
     */
    protected function contentsOf(string $type, array $chunks): array
    {
        $contents = [];
        foreach ($chunks as $chunk) {
            $this->assertInstanceOf($type, $chunk);
            $contents[] = $chunk->content;
        }

        return $contents;
    }
}
