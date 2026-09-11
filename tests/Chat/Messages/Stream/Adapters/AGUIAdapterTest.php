<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters;

use DateTimeImmutable;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
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
            'messageId' => 'reasoning_msg_test',
        ], json_decode(substr($events[0], 6, -2), true));
        $this->assertSame([
            'type' => 'REASONING_END',
            'messageId' => 'reasoning_msg_test',
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

    public function test_argument_fragments_are_buffered_until_committed_frontend_dispatch(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        foreach (['{"operation":', '"add"}'] as $delta) {
            $this->assertSame([], iterator_to_array($adapter->transform(new ToolArgumentChunk('msg', 'calculator', $delta, 'call_1')), false));
        }
        $call = new ToolCall('calculator', 'call_1', ['operation' => 'add'], deferred: true);
        $this->assertSame([], iterator_to_array($adapter->transform(new ToolCallChunk($call)), false));
        $events = $this->decode($adapter->suspended([(new ToolResultsRequest([$call]))->withId(1)]));
        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame('{"operation":"add"}', $events[1]['delta'] . $events[2]['delta']);
        $this->assertArrayNotHasKey('outcome', $events[4]);
    }

    public function test_local_tool_call_is_published_with_its_result(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg', 'calculator', '{"operation":"add"}', 'call_1')), false);
        $tool = $this->createMockTool('calculator', ['operation' => 'add'])->setCallId('call_1');
        $this->assertSame([], iterator_to_array($adapter->transform(new ToolCallChunk($tool)), false));
        $tool->setResult('42');
        $events = $this->decode($adapter->transform(new ToolResultChunk($tool)));
        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'TOOL_CALL_RESULT'], array_column($events, 'type'));
        $this->assertSame('call_1', $events[3]['toolCallId']);
        $this->assertSame('42', $events[3]['content']);
    }

    public function test_buffered_provider_dispatch_includes_complete_arguments(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        $call = new ToolCall('calculator', 'call_1', ['operation' => 'add'], deferred: true);
        $events = $this->decode($adapter->suspended([(new ToolResultsRequest([$call]))->withId(1)]));
        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame('{"operation":"add"}', $events[1]['delta']);
    }

    public function test_approval_interrupt_does_not_publish_an_executable_tool_call(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->transform(new TextChunk('msg_123', 'Let me check')), false);
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg_123', 'geolocation_get', '{"save":true}', 'call_1')), false);
        $events = $this->decode($adapter->suspended([$this->approval('call_1', 'Consent required')]));
        $this->assertSame(['TEXT_MESSAGE_END', 'STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame('msg_123', $events[2]['messages'][0]['id']);
        $this->assertArrayNotHasKey('toolCalls', $events[2]['messages'][0]);
        $interrupt = $events[3]['outcome']['interrupts'][0];
        $this->assertSame('call_1', $interrupt['id']);
        $this->assertSame('confirmation', $interrupt['reason']);
        $this->assertSame('Consent required', $interrupt['message']);
        $this->assertSame('geolocation_get', $interrupt['metadata']['name']);
    }

    public function test_buffered_approval_has_a_snapshot_and_response_schema(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        $events = $this->decode($adapter->suspended([$this->approval('call_1')]));
        $this->assertSame(['STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED'], array_column($events, 'type'));
        $interrupt = $events[2]['outcome']['interrupts'][0];
        $this->assertSame('call_1', $interrupt['id']);
        $this->assertSame(['approved'], $interrupt['responseSchema']['required']);
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

        $this->assertCount(3, $events);
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
        ], $events[2]['outcome']['interrupts'][0]);
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
