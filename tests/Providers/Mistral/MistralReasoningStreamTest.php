<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Mistral;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function implode;

class MistralReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('thinking_sequences')]
    public function test_empty_thoughts_preserve_finish_reason(array $fragments, array $expected): void
    {
        $events = [['choices' => [['index' => 0, 'delta' => ['content' => 'Answer'], 'finish_reason' => null]]]];
        foreach ($fragments as $fragment) {
            $events[] = ['choices' => [['index' => 1, 'delta' => ['content' => [['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => $fragment]]]]], 'finish_reason' => null]]];
        }
        $events[] = [
            'choices' => [['index' => 1, 'delta' => ['content' => [['type' => 'thinking', 'thinking' => []]]], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
        ];
        $provider = new Mistral('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);

        $this->assertCount(2, $message->getContentBlocks());
        $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
        $this->assertSame('Answer', $message->getContent());
        $this->assertInstanceOf(TextChunk::class, $chunks[0]);
        $this->assertSame('stop', $message->getMetadata('stop_reason'));
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
    }

    public static function thinking_sequences(): array
    {
        return [...self::reasoning_sequences(), 'empty thinking list' => [[], []]];
    }
}
