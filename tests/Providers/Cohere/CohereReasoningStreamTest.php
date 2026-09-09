<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Cohere;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Cohere\Cohere;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;

class CohereReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('reasoning_sequences')]
    public function test_reasoning_chunks_preserve_state_and_text(array $fragments, array $expected): void
    {
        $events = [['type' => 'message-start', 'id' => 'msg-test']];
        foreach ($fragments as $fragment) {
            $events[] = ['type' => 'content-delta', 'index' => 0, 'delta' => ['message' => ['content' => ['thinking' => $fragment]]]];
        }
        $events[] = ['type' => 'content-delta', 'index' => 1, 'delta' => ['message' => ['content' => ['text' => 'Answer']]]];
        $events[] = ['type' => 'message-end', 'usage' => ['tokens' => ['input_tokens' => 3, 'output_tokens' => 4]]];
        $provider = new Cohere('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);

        $this->assertCount(2, $message->getContentBlocks());
        $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
        $this->assertSame('Answer', $message->getContent());
        $this->assertInstanceOf(TextChunk::class, $chunks[count($chunks) - 1]);
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
        foreach ($chunks as $chunk) {
            $this->assertSame('msg-test', $chunk->messageId);
        }
    }
}
