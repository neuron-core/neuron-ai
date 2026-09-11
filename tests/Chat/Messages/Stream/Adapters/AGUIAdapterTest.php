<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters;

use DateTimeImmutable;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function array_column;
use function array_key_last;
use function iterator_to_array;
use function json_decode;
use function substr;

class AGUIAdapterTest extends TestCase
{
    public function test_error_emits_run_error_with_optional_code(): void
    {
        foreach ([0, 503] as $code) {
            $adapter = new AGUIAdapter('thread_test', 'run_test');
            iterator_to_array($adapter->start(), false);

            $message = "Provider failed: \"unavailable\"\nPlease retry.";
            $events = iterator_to_array($adapter->error(new RuntimeException($message, $code)), false);

            $expected = ['type' => 'RUN_ERROR', 'message' => $message];
            if ($code !== 0) {
                $expected['code'] = (string) $code;
            }

            $this->assertCount(1, $events);
            $this->assertStringStartsWith('data: ', $events[0]);
            $this->assertStringEndsWith("\n\n", $events[0]);
            $this->assertSame($expected, json_decode(substr($events[0], 6, -2), true));
        }
    }

    public function test_error_closes_active_text_before_run_error(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->start(), false);
        $text = iterator_to_array($adapter->transform(new TextChunk('msg_test', 'Hello')), false);
        $messageId = json_decode(substr($text[0], 6, -2), true)['messageId'];

        $events = iterator_to_array($adapter->error(new RuntimeException('Failed')), false);

        $this->assertCount(2, $events);
        $this->assertSame([
            'type' => 'TEXT_MESSAGE_END',
            'messageId' => $messageId,
        ], json_decode(substr($events[0], 6, -2), true));
        $this->assertStringContainsString('"type":"RUN_ERROR"', $events[1]);
        $this->assertSame([], iterator_to_array($adapter->end(), false));
    }

    public function test_error_closes_active_reasoning_before_run_error(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->transform(new ReasoningChunk('msg_test', 'Thinking')), false);

        $events = iterator_to_array($adapter->error(new RuntimeException('Failed')), false);

        $this->assertCount(3, $events);
        $this->assertSame([
            'type' => 'REASONING_MESSAGE_END',
            'messageId' => 'msg_test',
        ], json_decode(substr($events[0], 6, -2), true));
        $this->assertSame([
            'type' => 'REASONING_END',
            'messageId' => 'msg_test',
        ], json_decode(substr($events[1], 6, -2), true));
        $this->assertStringContainsString('"type":"RUN_ERROR"', $events[2]);
        $this->assertSame([], iterator_to_array($adapter->end(), false));
    }

    public function test_error_is_terminal(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->error(new RuntimeException('Failed')), false);

        $this->assertSame([], iterator_to_array($adapter->end(), false));
        $this->assertSame([], iterator_to_array($adapter->error(new RuntimeException('Failed again')), false));
        $this->assertSame([], iterator_to_array($adapter->transform(new TextChunk('msg_test', 'Late')), false));
        $this->assertSame([], iterator_to_array($adapter->start(), false));
    }

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

    public function test_suspended_closes_a_streamed_tool_call_and_finishes_with_the_interrupt_outcome(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->transform(new TextChunk('msg_123', 'Let me check')), false);
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg_123', 'geolocation_get', '{"save":true}', 'call_1')), false);

        $events = $this->decode($adapter->suspended([1 => $this->approval('call_1', 'Location access needs consent')]));

        // The argument chunk already closed the text message; only the tool call is still open.
        $this->assertSame(['TOOL_CALL_END', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame('call_1', $events[0]['toolCallId']);
        $this->assertSame([
            'type' => 'RUN_FINISHED',
            'threadId' => 'thread_test',
            'runId' => 'run_test',
            'outcome' => [
                'type' => 'interrupt',
                'interrupts' => [[
                    'id' => 'call_1',
                    'reason' => 'tool_call',
                    'toolCallId' => 'call_1',
                    'message' => 'Location access needs consent',
                    'metadata' => [
                        'id' => 'call_1',
                        'name' => 'geolocation_get',
                        'description' => null,
                        'decision' => 'pending',
                        'feedback' => null,
                        'reason' => 'Location access needs consent',
                        'inputs' => ['save' => true],
                    ],
                ]],
            ],
        ], $events[1]);
    }

    public function test_suspended_announces_a_pending_tool_call_that_never_reached_the_stream(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);

        $events = $this->decode($adapter->suspended([1 => $this->approval('call_1')]));

        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame(['type' => 'TOOL_CALL_START', 'toolCallId' => 'call_1', 'toolCallName' => 'geolocation_get'], $events[0]);
        $this->assertSame('{"save":true}', $events[1]['delta']);

        $interrupt = $events[3]['outcome']['interrupts'][0];
        $this->assertSame('call_1', $interrupt['toolCallId']);
        // Without a per-action reason the request message is the prompt.
        $this->assertSame('1 tool call requires approval', $interrupt['message']);
    }

    public function test_suspended_exposes_one_interrupt_per_action_with_its_decision_state(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);
        $request = (new ApprovalRequest('2 tool calls require approval', [
            new Action('call_a', 'delete_file', decision: ActionDecision::Approved, inputs: ['path' => '/tmp/a']),
            new Action('call_b', 'send_email', inputs: ['to' => 'team@example.com']),
        ]))->withId(1);

        $events = $this->decode($adapter->suspended([1 => $request]));

        $interrupts = $events[array_key_last($events)]['outcome']['interrupts'];
        $this->assertSame(['call_a', 'call_b'], array_column($interrupts, 'id'));
        $this->assertSame('approved', $interrupts[0]['metadata']['decision']);
        $this->assertSame('pending', $interrupts[1]['metadata']['decision']);
    }

    public function test_suspended_encodes_other_requests_under_a_namespaced_reason(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);
        $request = (new WaitForEventRequest('order.approved', new DateTimeImmutable('2026-09-11T10:00:00+00:00')))->withId(3);

        $events = $this->decode($adapter->suspended([3 => $request]));

        $this->assertCount(1, $events);
        $this->assertSame([
            'id' => '3',
            'reason' => 'neuron:wait_for_event',
            'message' => "Waiting for event 'order.approved' (expires at 2026-09-11T10:00:00+00:00)",
            'metadata' => [
                'interruptId' => 3,
                'type' => 'wait_for_event',
                'eventName' => 'order.approved',
                'expiresAt' => '2026-09-11T10:00:00+00:00',
            ],
            'expiresAt' => '2026-09-11T10:00:00+00:00',
        ], $events[0]['outcome']['interrupts'][0]);
    }

    public function test_suspended_is_silent_after_a_failed_run(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->error(new RuntimeException('Failed')), false);

        $this->assertSame([], iterator_to_array($adapter->suspended([1 => $this->approval('call_1')]), false));
    }

    private function approval(string $callId, ?string $reason = null): ApprovalRequest
    {
        $request = new ApprovalRequest('1 tool call requires approval', [
            new Action($callId, 'geolocation_get', reason: $reason, inputs: ['save' => true]),
        ]);

        return $request->withId(1);
    }

    /**
     * @param iterable<string> $frames
     * @return list<array<string, mixed>>
     */
    private function decode(iterable $frames): array
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
