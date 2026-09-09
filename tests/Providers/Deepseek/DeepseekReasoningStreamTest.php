<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Deepseek;

use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Deepseek\Deepseek;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeepseekReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('reasoning_paths')]
    public function test_reasoning_preserves_content_metadata_and_tools(array $fragments, array $expected, bool $tools): void
    {
        $events = [];
        foreach ($fragments as $index => $fragment) {
            $delta = ['reasoning_content' => $fragment];
            if ($tools) {
                $delta['tool_calls'] = $index === 0
                    ? [['index' => 0, 'id' => 'call-test', 'type' => 'function', 'function' => ['name' => 'lookup', 'arguments' => '{"query":']]]
                    : [];
            }
            $events[] = ['id' => 'msg-test', 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => null]]];
        }
        if ($tools) {
            $events[] = ['id' => 'msg-test', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '"test"}']]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4]];
        } else {
            $events[] = ['id' => 'msg-test', 'choices' => [['index' => 0, 'delta' => ['content' => 'Answer'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4]];
        }
        $provider = new Deepseek('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        $provider->setTools([new ToolStub('lookup')]);
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);

        $this->assertSame(implode('', $fragments), $message->getMetadata('reasoning_content'));
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
        foreach ($chunks as $chunk) {
            $this->assertSame('msg-test', $chunk->messageId);
        }
        if ($tools) {
            $this->assertInstanceOf(ToolCallMessage::class, $message);
            $this->assertSame(['query' => 'test'], $message->getToolCalls()[0]->getInputs());
            $arguments = array_values(array_filter($chunks, fn (StreamChunk $chunk): bool => $chunk instanceof ToolArgumentChunk));
            $this->assertSame(['{"query":', '"test"}'], array_map(fn (ToolArgumentChunk $chunk): string => $chunk->delta, $arguments));
            $this->assertNull($message->getReasoning());
        } else {
            $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
            $this->assertSame('Answer', $message->getContent());
        }
    }

    public static function reasoning_paths(): array
    {
        $cases = [];
        foreach (self::reasoning_sequences() as $name => $sequence) {
            $cases[$name.' content'] = [...$sequence, false];
            $cases[$name.' tools'] = [...$sequence, true];
        }
        return $cases;
    }

    public function test_absent_reasoning_preserves_parent_text(): void
    {
        $events = [
            ['choices' => [['index' => 0, 'delta' => ['reasoning_content' => null, 'content' => 'Answer']]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => '!']]]],
        ];
        $provider = new Deepseek('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        $provider->setTools([new ToolStub('lookup')]);
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), []);
        $this->assertCount(2, $chunks);
        $this->assertSame('Answer!', $message->getContent());
        $this->assertNull($message->getReasoning());
        $this->assertNull($message->getMetadata('reasoning_content'));
    }
}
