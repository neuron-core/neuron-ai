<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class GeminiReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('reasoning_sequences')]
    public function test_reasoning_chunks_preserve_state_and_completion(array $fragments, array $expected): void
    {
        $events = [];
        foreach ($fragments as $fragment) {
            $events[] = ['candidates' => [['content' => ['parts' => [['thought' => true, 'text' => $fragment]]]]]];
        }
        $events[] = [
            'candidates' => [['content' => ['parts' => [['text' => 'Answer']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 3, 'candidatesTokenCount' => 4, 'thoughtsTokenCount' => 2],
        ];
        $provider = new Gemini('test', 'model', httpClient: $this->streamClient(json_encode($events, JSON_THROW_ON_ERROR)));
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);

        $this->assertCount(2, $message->getContentBlocks());
        $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
        $this->assertSame('Answer', $message->getContent());
        $this->assertInstanceOf(TextChunk::class, $chunks[count($chunks) - 1]);
        $this->assertSame('STOP', $message->getMetadata('stop_reason'));
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
        $this->assertSame(2, $message->getUsage()->reasoningTokens);
    }
}
