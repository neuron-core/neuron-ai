<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Support;

use Generator;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\Providers\ProviderResponse;

trait ReasoningStreamAssertions
{
    public static function reasoning_sequences(): array
    {
        return [
            'empty' => [[''], []],
            'text' => [['ab'], ['ab']],
            'zero' => [['0'], ['0']],
            'space' => [[' '], [' ']],
            'newline' => [["\n"], ["\n"]],
            'interleaved' => [['', 'ab', '', '0', '', ' ', "\n", ''], ['ab', '0', ' ', "\n"]],
        ];
    }

    protected function streamClient(string $body): GuzzleHttpClient
    {
        return new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
            new Response(200, body: $body),
        ])));
    }

    protected function sse(array $events): string
    {
        return implode('', array_map(
            fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n",
            $events,
        ));
    }

    /**
     * @param Generator<int, StreamChunk, mixed, ProviderResponse> $stream
     * @param string[] $expected
     * @return array{0: StreamChunk[], 1: Message}
     */
    protected function consumeReasoningStream(Generator $stream, array $expected): array
    {
        $chunks = iterator_to_array($stream, false);
        $reasoning = array_values(array_filter($chunks, fn (StreamChunk $chunk): bool => $chunk instanceof ReasoningChunk));
        $this->assertSame($expected, array_map(fn (ReasoningChunk $chunk): string => $chunk->content, $reasoning));
        if ($reasoning !== []) {
            $this->assertNotSame('', $reasoning[0]->messageId);
            foreach ($reasoning as $chunk) {
                $this->assertSame($reasoning[0]->messageId, $chunk->messageId);
            }
        }

        return [$chunks, $stream->getReturn()->message()];
    }
}
