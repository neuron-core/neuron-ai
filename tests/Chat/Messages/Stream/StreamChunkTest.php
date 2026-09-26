<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream;

use NeuronAI\Chat\Messages\Stream\Chunks\AudioChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ImageChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\StreamChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamChunkTest extends TestCase
{
    /**
     * The payload shape streaming adapters send to clients.
     *
     * @return array<string, array{StreamChunk, array<string, mixed>}>
     */
    public static function payloads(): array
    {
        return [
            'text' => [new TextChunk('msg_1', 'Hel'), ['messageId' => 'msg_1', 'content' => 'Hel']],
            'reasoning' => [new ReasoningChunk('msg_1', 'hmm'), ['messageId' => 'msg_1', 'content' => 'hmm']],
            'image' => [new ImageChunk('msg_1', 'aGVsbG8='), ['messageId' => 'msg_1', 'content' => 'aGVsbG8=']],
            'audio' => [new AudioChunk('msg_1', 'UklGRg=='), ['messageId' => 'msg_1', 'content' => 'UklGRg==']],
            'tool argument' => [
                new ToolArgumentChunk('msg_1', 'lookup', '{"que', 'call-1'),
                ['messageId' => 'msg_1', 'toolName' => 'lookup', 'toolCallId' => 'call-1', 'delta' => '{"que'],
            ],
            'tool argument without ids' => [
                new ToolArgumentChunk(null, 'lookup', 'ry":1}'),
                ['messageId' => null, 'toolName' => 'lookup', 'toolCallId' => null, 'delta' => 'ry":1}'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('payloads')]
    public function test_to_array_exposes_the_chunk_payload(StreamChunk $chunk, array $expected): void
    {
        $this->assertSame($expected, $chunk->toArray());
    }

    public function test_tool_call_chunk_carries_the_serialized_call(): void
    {
        $call = new ToolCall('lookup', 'call-1', ['query' => 'neuron']);

        $this->assertSame(
            ['messageId' => 'msg_1', 'tool' => $call->jsonSerialize()],
            (new ToolCallChunk('msg_1', $call))->toArray()
        );
    }

    public function test_tool_result_chunk_belongs_to_no_message(): void
    {
        $call = (new ToolCall('lookup', 'call-1', ['query' => 'neuron']))->setResult('found');
        $chunk = new ToolResultChunk($call);

        $this->assertNull($chunk->messageId);
        $this->assertSame(['messageId' => null, 'tool' => $call->jsonSerialize()], $chunk->toArray());
        $this->assertSame('found', $chunk->toArray()['tool']['result']);
    }
}
