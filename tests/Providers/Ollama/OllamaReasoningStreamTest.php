<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function implode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

class OllamaReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('reasoning_sequences')]
    public function test_reasoning_preserves_zero_and_usage(array $fragments, array $expected): void
    {
        $events = [];
        foreach ($fragments as $fragment) {
            $events[] = ['message' => ['role' => 'assistant', 'thinking' => $fragment, 'content' => ''], 'done' => false];
        }
        $events[] = ['message' => ['role' => 'assistant', 'thinking' => '', 'content' => ''], 'done' => true, 'prompt_eval_count' => 3, 'eval_count' => 4];
        $provider = $this->provider($events);
        [, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);

        $reasoning = implode('', $fragments);
        $this->assertSame($reasoning === '' ? null : $reasoning, $message->getReasoning()?->content);
        $this->assertCount($reasoning === '' ? 0 : 1, $message->getContentBlocks());
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
    }

    #[DataProvider('absent_thinking')]
    public function test_empty_thinking_falls_through_to_text(array $thinking): void
    {
        $provider = $this->provider([
            ['message' => ['role' => 'assistant', 'content' => 'Answer', ...$thinking], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => '', ...$thinking], 'done' => true, 'eval_count' => 4],
        ]);
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), []);
        $this->assertCount(1, $chunks);
        $this->assertInstanceOf(TextChunk::class, $chunks[0]);
        $this->assertSame('Answer', $message->getContent());
        $this->assertNull($message->getReasoning());
        $this->assertSame(4, $message->getUsage()->outputTokens);
    }

    public static function absent_thinking(): array
    {
        return ['empty' => [['thinking' => '']], 'null' => [['thinking' => null]], 'missing' => [[]]];
    }

    protected function provider(array $events): Ollama
    {
        $body = implode("\n", array_map(fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR), $events))."\n";
        return new Ollama('http://localhost/api', 'model', httpClient: $this->streamClient($body));
    }
}
