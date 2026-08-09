<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Adapters;

use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;

class AGUIAdapterTest extends TestCase
{
    public function test_tool_argument_chunks_stream_start_and_args_deltas(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        // Open a text stream so the tool call gets a parent message id
        $textEvents = iterator_to_array($adapter->transform(new TextChunk('msg_123', 'Let me check')), false);
        $this->assertStringContainsString('"type":"TEXT_MESSAGE_START"', $textEvents[0]);

        $first = iterator_to_array($adapter->transform(
            new ToolArgumentChunk('msg_123', 'calculator', '{"operation":', 'call_1')
        ), false);

        // First fragment closes the text stream, then starts the tool call
        $this->assertCount(3, $first);
        $this->assertStringContainsString('"type":"TEXT_MESSAGE_END"', $first[0]);
        $this->assertStringContainsString('"type":"TOOL_CALL_START"', $first[1]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $first[1]);
        $this->assertStringContainsString('"toolCallName":"calculator"', $first[1]);
        $this->assertStringContainsString('"parentMessageId"', $first[1]);
        $this->assertStringContainsString('"type":"TOOL_CALL_ARGS"', $first[2]);
        $this->assertStringContainsString('"delta":"{\"operation\":"', $first[2]);

        $second = iterator_to_array($adapter->transform(
            new ToolArgumentChunk('msg_123', 'calculator', '"add"}', 'call_1')
        ), false);

        // Subsequent fragments only emit args deltas
        $this->assertCount(1, $second);
        $this->assertStringContainsString('"type":"TOOL_CALL_ARGS"', $second[0]);
        $this->assertStringContainsString('"delta":"\"add\"}"', $second[0]);
    }

    public function test_tool_call_chunk_after_streamed_arguments_only_ends_the_call(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg_123', 'calculator', '{"operation":"add"}', 'call_1')), false);

        $tool = $this->createMockTool('calculator', ['operation' => 'add'])->setCallId('call_1');
        $events = iterator_to_array($adapter->transform(new ToolCallChunk($tool)), false);

        // Start and args were already streamed: only TOOL_CALL_END is emitted
        $this->assertCount(1, $events);
        $this->assertStringContainsString('"type":"TOOL_CALL_END"', $events[0]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $events[0]);

        $tool->setResult('42');
        $events = iterator_to_array($adapter->transform(new ToolResultChunk($tool)), false);

        $this->assertCount(1, $events);
        $this->assertStringContainsString('"type":"TOOL_CALL_RESULT"', $events[0]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $events[0]);
    }

    public function test_tool_call_chunk_without_streamed_arguments_emits_full_sequence(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        // One-shot providers (e.g. Gemini, Ollama) yield no ToolArgumentChunk
        $tool = $this->createMockTool('calculator', ['operation' => 'add'])->setCallId('call_1');
        $events = iterator_to_array($adapter->transform(new ToolCallChunk($tool)), false);

        $this->assertCount(3, $events);
        $this->assertStringContainsString('"type":"TOOL_CALL_START"', $events[0]);
        $this->assertStringContainsString('"type":"TOOL_CALL_ARGS"', $events[1]);
        $this->assertStringContainsString('"delta":"{\"operation\":\"add\"}"', $events[1]);
        $this->assertStringContainsString('"type":"TOOL_CALL_END"', $events[2]);
    }

    private function createMockTool(string $name, array $inputs): ToolCall
    {
        return ToolCall::make($name, null, $inputs, 'Mock tool');
    }
}
