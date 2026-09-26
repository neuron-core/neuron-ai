<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\ZAI;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\Providers\ZAI\ZAI;
use PHPUnit\Framework\TestCase;

use function array_map;
use function iterator_to_array;

class ZAIStreamReasoningTest extends TestCase
{
    protected function provider(string $body): ZAI
    {
        return new ZAI('key', 'glm-4.6', httpClient: new GuzzleHttpClient(
            handler: HandlerStack::create(new MockHandler([new Response(200, body: $body)]))
        ));
    }

    public function test_stream_keeps_reasoning_content_like_chat_does(): void
    {
        $provider = $this->provider(
            'data: {"choices":[{"index":0,"delta":{"role":"assistant","reasoning_content":"Think"}}]}'."\n\n"
            .'data: {"choices":[{"index":0,"delta":{"reasoning_content":"ing"}}]}'."\n\n"
            .'data: {"choices":[{"index":0,"delta":{"content":"Answer"},"finish_reason":"stop"}]}'."\n\n"
            ."data: [DONE]\n\n"
        );

        $stream = $provider->stream(new UserMessage('Q'));
        $chunks = iterator_to_array($stream, false);

        $this->assertSame(
            [[ReasoningChunk::class, 'Think'], [ReasoningChunk::class, 'ing'], [TextChunk::class, 'Answer']],
            array_map(static fn (object $chunk): array => [$chunk::class, $chunk->content], $chunks)
        );
        $message = $stream->getReturn()->message();
        $this->assertSame('Thinking', $message->getReasoning()?->content);
        $this->assertSame('Answer', $message->getContent());
    }
}
