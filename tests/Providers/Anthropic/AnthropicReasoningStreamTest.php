<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Anthropic\MessageMapper;
use NeuronAI\Tests\Chat\History\Stub\TestableChatHistory;
use NeuronAI\Tests\Support\ReasoningStreamAssertions;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnthropicReasoningStreamTest extends TestCase
{
    use ReasoningStreamAssertions;

    #[DataProvider('signed_sequences')]
    public function test_signed_reasoning_survives_tool_turn(array $fragments, array $expected): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg-test', 'usage' => ['input_tokens' => 3]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']],
        ];
        foreach ($fragments as $fragment) {
            $events[] = ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => $fragment]];
        }
        $events = [...$events,
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'signed-test']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'call-test', 'name' => 'lookup', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"query":"test"}']],
            ['type' => 'content_block_stop', 'index' => 1],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 4]],
            ['type' => 'message_stop'],
        ];
        $provider = new Anthropic('test', 'model', httpClient: $this->streamClient($this->sse($events)));
        $provider->setTools([new ToolStub('lookup')]);
        [$chunks, $message] = $this->consumeReasoningStream($provider->stream(new UserMessage('Question')), $expected);

        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertCount(1, $message->getContentBlocks());
        $this->assertSame(implode('', $fragments), $message->getReasoning()?->content);
        $this->assertSame('signed-test', $message->getReasoning()->id);
        $this->assertSame(['query' => 'test'], $message->getToolCalls()[0]->getInputs());
        $this->assertInstanceOf(ToolArgumentChunk::class, $chunks[count($chunks) - 1]);
        $this->assertSame(3, $message->getUsage()->inputTokens);
        $this->assertSame(4, $message->getUsage()->outputTokens);
        foreach ($chunks as $chunk) {
            $this->assertSame('msg-test', $chunk->messageId);
        }

        $expectedContent = [
            ['type' => 'thinking', 'thinking' => implode('', $fragments), 'signature' => 'signed-test'],
            ['type' => 'tool_use', 'id' => 'call-test', 'name' => 'lookup', 'input' => ['query' => 'test']],
        ];
        $mapper = new MessageMapper();
        $this->assertSame($expectedContent, $mapper->map([$message])[0]['content']);
        $restored = (new TestableChatHistory())->publicDeserialize(json_decode(json_encode([$message], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($expectedContent, $mapper->map($restored)[0]['content']);
    }

    public static function signed_sequences(): array
    {
        return [...self::reasoning_sequences(), 'signature only' => [[], []]];
    }
}
