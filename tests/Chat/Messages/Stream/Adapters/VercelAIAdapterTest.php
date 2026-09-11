<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters;

use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function array_column;
use function array_pop;
use function iterator_to_array;
use function json_decode;
use function substr;

class VercelAIAdapterTest extends TestCase
{
    private VercelAIAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new VercelAIAdapter();
    }

    public function test_get_headers_returns_correct_headers(): void
    {
        $headers = $this->adapter->getHeaders();

        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('text/event-stream', $headers['Content-Type']);
        $this->assertArrayHasKey('x-vercel-ai-ui-message-stream', $headers);
        $this->assertEquals('v1', $headers['x-vercel-ai-ui-message-stream']);
    }

    public function test_start_returns_empty_array(): void
    {
        $this->assertEmpty($this->adapter->start());
    }

    public function test_end_returns_finish_and_done_messages(): void
    {
        $result = iterator_to_array($this->adapter->end());

        $this->assertCount(2, $result);
        $this->assertStringContainsString('"type":"finish"', $result[0]);
        $this->assertStringContainsString('[DONE]', $result[1]);
    }

    public function test_error_emits_error_text_and_done(): void
    {
        $message = "Provider failed: \"unavailable\"\nPlease retry.";
        $result = iterator_to_array($this->adapter->error(new RuntimeException($message, 503)), false);

        $this->assertCount(2, $result);
        $this->assertStringStartsWith('data: ', $result[0]);
        $this->assertStringEndsWith("\n\n", $result[0]);
        $this->assertSame([
            'type' => 'error',
            'errorText' => $message,
        ], json_decode(substr($result[0], 6, -2), true));
        $this->assertSame("data: [DONE]\n\n", $result[1]);
    }

    public function test_error_is_terminal_after_streaming(): void
    {
        iterator_to_array($this->adapter->transform(new TextChunk('msg_test', 'Hello')), false);
        iterator_to_array($this->adapter->error(new RuntimeException('Failed')), false);

        $this->assertSame([], iterator_to_array($this->adapter->end(), false));
        $this->assertSame([], iterator_to_array($this->adapter->error(new RuntimeException('Failed again')), false));
        $this->assertSame([], iterator_to_array($this->adapter->transform(new TextChunk('msg_test', 'Late')), false));
        $this->assertSame([], $this->adapter->start());
    }

    public function test_transform_text_chunk(): void
    {
        $events = $this->decode($this->adapter->transform(new TextChunk('msg_123', 'Hello world')));
        $this->assertSame(['start', 'text-start', 'text-delta'], array_column($events, 'type'));
        $this->assertSame('msg_123', $events[0]['messageId']);
        $this->assertSame($events[1]['id'], $events[2]['id']);
        $this->assertSame('Hello world', $events[2]['delta']);
    }

    public function test_transform_reasoning_chunk(): void
    {
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);
        $events = $this->decode($this->adapter->transform(new ReasoningChunk('sig_123', 'Thinking...')));
        $this->assertSame(['text-end', 'reasoning-start', 'reasoning-delta'], array_column($events, 'type'));
        $this->assertSame($events[1]['id'], $events[2]['id']);
        $this->assertSame('Thinking...', $events[2]['delta']);
    }

    public function test_tool_call_chunk_previews_without_authorizing_frontend_execution(): void
    {
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);
        $tool = $this->createMockTool('calculator', ['operation' => 'add']);
        $events = $this->decode($this->adapter->transform(new ToolCallChunk($tool)));
        $this->assertSame(['text-end', 'tool-input-start', 'tool-input-delta'], array_column($events, 'type'));
        $this->assertSame('calculator', $events[1]['toolName']);
        $this->assertSame('{"operation":"add"}', $events[2]['inputTextDelta']);
    }

    public function test_transform_tool_result_chunk(): void
    {
        // Initialize and call tool first (consume the generators so they execute)
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);
        $tool = $this->createMockTool('calculator', ['operation' => 'add']);
        iterator_to_array($this->adapter->transform(new ToolCallChunk($tool)), false);

        // Now send result
        $tool->setResult('42');
        $chunk = new ToolResultChunk($tool);

        $result = iterator_to_array($this->adapter->transform($chunk), false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"tool-output-available"', $result[0]);
        $this->assertStringContainsString('"output":"42"', $result[0]);
    }

    public function test_transform_tool_argument_chunks_stream_input_deltas(): void
    {
        // Initialize with a text chunk first (consume the generator so it executes)
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);

        $first = iterator_to_array($this->adapter->transform(
            new ToolArgumentChunk('msg_123', 'calculator', '{"operation":', 'call_1')
        ), false);

        $this->assertStringContainsString('"type":"text-end"', array_shift($first));
        $this->assertCount(2, $first);
        $this->assertStringContainsString('"type":"tool-input-start"', $first[0]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $first[0]);
        $this->assertStringContainsString('"toolName":"calculator"', $first[0]);
        $this->assertStringContainsString('"type":"tool-input-delta"', $first[1]);
        $this->assertStringContainsString('"inputTextDelta":"{\"operation\":"', $first[1]);

        $second = iterator_to_array($this->adapter->transform(
            new ToolArgumentChunk('msg_123', 'calculator', '"add"}', 'call_1')
        ), false);

        // Subsequent fragments only emit deltas
        $this->assertCount(1, $second);
        $this->assertStringContainsString('"type":"tool-input-delta"', $second[0]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $second[0]);
    }

    public function test_tool_call_chunk_reuses_streamed_call_id(): void
    {
        iterator_to_array($this->adapter->transform(new ToolArgumentChunk('msg_123', 'calculator', '{}', 'call_1')), false);
        $tool = $this->createMockTool('calculator', []);
        $this->assertSame([], iterator_to_array($this->adapter->transform(new ToolCallChunk($tool)), false));
        $tool->setResult('42');
        $events = $this->decode($this->adapter->transform(new ToolResultChunk($tool)));
        $this->assertSame('call_1', $events[0]['toolCallId']);
    }

    public function test_message_and_part_ids_are_consistent_across_chunks(): void
    {
        $first = $this->decode($this->adapter->transform(new TextChunk('msg_123', 'Hello')));
        $second = $this->decode($this->adapter->transform(new TextChunk('msg_123', ' world')));
        $end = $this->decode(array_slice(iterator_to_array($this->adapter->end(), false), 0, -1));
        $this->assertSame('msg_123', $first[0]['messageId']);
        $this->assertSame(['text-delta'], array_column($second, 'type'));
        $this->assertSame($first[1]['id'], $second[0]['id']);
        $this->assertSame(['text-end', 'finish'], array_column($end, 'type'));
        $this->assertSame($first[1]['id'], $end[0]['id']);
    }

    public function test_sse_format_is_correct(): void
    {
        $chunk = new TextChunk('msg_123', 'Test');
        $result = iterator_to_array($this->adapter->transform($chunk), false);

        // Start, part start, and delta must all survive collection.
        $this->assertCount(3, $result);

        foreach ($result as $line) {
            $this->assertStringStartsWith('data: ', $line);
            $this->assertStringEndsWith("\n\n", $line);

            // Extract JSON and validate
            $json = substr($line, 6, -2); // Remove "data: " and "\n\n"
            $decoded = json_decode($json, true);
            $this->assertNotNull($decoded);
            $this->assertArrayHasKey('type', $decoded);
        }
    }

    public function test_suspended_requests_approval_without_dispatching_the_tool(): void
    {
        iterator_to_array($this->adapter->transform(new ToolArgumentChunk('msg_123', 'delete_file', '{"path":"/tmp/x"}', 'call_1')), false);
        $request = (new ApprovalRequest('Approve', [
            new Action('call_1', 'delete_file', reason: 'Consent required', inputs: ['path' => '/tmp/x']),
        ]))->withId(1);
        $frames = iterator_to_array($this->adapter->suspended([$request]), false);
        $this->assertSame("data: [DONE]\n\n", array_pop($frames));
        $events = $this->decode($frames);
        $this->assertSame(['tool-approval-request', 'finish'], array_column($events, 'type'));
        $this->assertSame('call_1', $events[0]['approvalId']);
        $this->assertSame('Consent required', $events[0]['reason']);
    }

    public function test_suspended_starts_the_message_before_a_buffered_tool_call(): void
    {
        $request = (new ApprovalRequest('1 tool call requires approval', [
            new Action('call_1', 'delete_file', inputs: ['path' => '/tmp/x']),
        ]))->withId(1);

        $frames = iterator_to_array($this->adapter->suspended([1 => $request]), false);

        $this->assertSame("data: [DONE]\n\n", array_pop($frames));
        $events = $this->decode($frames);
        $this->assertSame(['start', 'tool-input-start', 'tool-input-delta', 'tool-approval-request', 'finish'], array_column($events, 'type'));
        // Without a per-action reason the request message is the prompt.
        $this->assertSame('1 tool call requires approval', $events[3]['reason']);
    }

    public function test_suspended_encodes_other_requests_as_transient_data(): void
    {
        $request = (new WaitForEventRequest('order.approved'))->withId(3);

        $frames = iterator_to_array($this->adapter->suspended([3 => $request]), false);

        $this->assertSame("data: [DONE]\n\n", array_pop($frames));
        $this->assertSame([
            [
                'type' => 'data-workflow-interrupt',
                'data' => [
                    'interruptId' => 3,
                    'type' => 'wait_for_event',
                    'eventName' => 'order.approved',
                    'expiresAt' => null,
                ],
                'transient' => true,
            ],
            ['type' => 'finish'],
        ], $this->decode($frames));
    }

    public function test_suspended_is_silent_after_a_failed_run(): void
    {
        iterator_to_array($this->adapter->error(new RuntimeException('Failed')), false);

        $request = (new WaitForEventRequest('order.approved'))->withId(1);
        $this->assertSame([], iterator_to_array($this->adapter->suspended([1 => $request]), false));
    }

    /**
     * @param iterable<string> $frames
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $frames): array
    {
        $events = [];
        foreach ($frames as $frame) {
            $events[] = json_decode(substr($frame, 6, -2), true);
        }

        return $events;
    }

    private function createMockTool(string $name, array $inputs): ToolCall
    {
        return ToolCall::make($name, null, $inputs, 'Mock tool');
    }
}
