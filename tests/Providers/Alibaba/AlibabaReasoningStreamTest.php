<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Alibaba;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Alibaba\DashScopeOpenAI;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AlibabaReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('reasoning_sequences')]
    public function test_reasoning_preserves_blocks_and_branch_precedence(array $fragments, array $expected): void
    {
        $events = [];
        foreach ($fragments as $fragment) {
            $events[] = ['id' => 'msg-test', 'choices' => [['index' => 0, 'delta' => ['reasoning_content' => $fragment, 'content' => 'Ignored']]]];
        }
        $provider = new DashScopeOpenAI('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);
        $this->assertCount(count($expected), $chunks);
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
        $this->assertNull($message->getContent());
        foreach ($chunks as $chunk) {
            $this->assertSame('msg-test', $chunk->messageId);
        }
    }

    public function test_absent_reasoning_delegates_to_parent_text(): void
    {
        $events = [
            ['choices' => [['index' => 0, 'delta' => ['reasoning_content' => null, 'content' => 'Answer']]]],
            ['choices' => [['index' => 0, 'delta' => ['content' => '!']]]],
        ];
        $provider = new DashScopeOpenAI('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), []);
        $this->assertCount(2, $chunks);
        $this->assertSame('Answer!', $message->getContent());
        $this->assertNull($message->getReasoning());
    }
}
