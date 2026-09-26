<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

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
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ActionDecision;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_column;
use function array_filter;
use function array_key_last;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function array_values;

class AGUIAdapterTest extends TestCase
{
    public function test_error_emits_run_error_with_optional_code(): void
    {
        foreach ([0, 503] as $code) {
            $adapter = new AGUIAdapter('thread_test', 'run_test');
            iterator_to_array($adapter->start(), false);

            $message = "Provider failed: \"unavailable\"\nPlease retry.";
            $events = iterator_to_array($adapter->error(new RuntimeException($message, $code)), false);

            $expected = ['type' => 'RUN_ERROR', 'message' => 'The run failed.'];
            if ($code !== 0) {
                $expected['code'] = (string) $code;
            }

            $this->assertCount(1, $events);
            $this->assertSame($expected, json_decode(json_encode($events[0]), true));
        }
    }

    public function test_error_message_hook_decides_the_wire_text(): void
    {
        $adapter = new class ('thread_test') extends AGUIAdapter {
            protected function errorMessage(Throwable $error): string
            {
                return 'Visible: ' . $error->getMessage();
            }
        };
        iterator_to_array($adapter->start(), false);

        $events = iterator_to_array($adapter->error(new RuntimeException('provider down')), false);

        $this->assertSame('Visible: provider down', $events[0]->data['message']);
    }

    public function test_error_closes_active_text_before_run_error(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->start(), false);
        $text = iterator_to_array($adapter->transform(new TextChunk('msg_test', 'Hello')), false);
        $messageId = $text[0]->data['messageId'];

        $events = iterator_to_array($adapter->error(new RuntimeException('Failed')), false);

        $this->assertCount(2, $events);
        $this->assertSame([
            'type' => 'TEXT_MESSAGE_END',
            'messageId' => $messageId,
        ], json_decode(json_encode($events[0]), true));
        $this->assertSame('RUN_ERROR', $events[1]->type);
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
        ], json_decode(json_encode($events[0]), true));
        $this->assertSame([
            'type' => 'REASONING_END',
            'messageId' => 'reasoning_msg_test',
        ], json_decode(json_encode($events[1]), true));
        $this->assertSame('RUN_ERROR', $events[2]->type);
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
        $this->assertSame([], iterator_to_array($adapter->transform(new ToolCallChunk('msg', $call)), false));
        $events = $this->decode($adapter->interrupt((new ToolResultsRequest([$call]))->withId(1)));
        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame('{"operation":"add"}', $events[1]['delta'] . $events[2]['delta']);
        $this->assertArrayNotHasKey('outcome', $events[4]);
    }

    public function test_local_tool_call_is_published_with_its_result(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg', 'calculator', '{"operation":"add"}', 'call_1')), false);
        $tool = $this->createMockTool('calculator', ['operation' => 'add'])->setCallId('call_1');
        $this->assertSame([], iterator_to_array($adapter->transform(new ToolCallChunk('msg', $tool)), false));
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
        $events = $this->decode($adapter->interrupt((new ToolResultsRequest([$call]))->withId(1)));
        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertSame('{"operation":"add"}', $events[1]['delta']);
    }

    public function test_approval_interrupt_does_not_publish_an_executable_tool_call(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->transform(new TextChunk('msg_123', 'Let me check')), false);
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg_123', 'geolocation_get', '{"save":true}', 'call_1')), false);
        $events = $this->decode($adapter->interrupt($this->approval('call_1', 'Consent required')));
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
        $events = $this->decode($adapter->interrupt($this->approval('call_1')));
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

        $events = $this->decode($adapter->interrupt($request));

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

        $events = $this->decode($adapter->interrupt($request));

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

        $this->assertSame([], iterator_to_array($adapter->interrupt($this->approval('call_1')), false));
    }

    public function test_a_new_message_id_closes_the_open_text_message(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');

        $events = [
            ...$this->decode($adapter->transform(new TextChunk('msg_1', 'First'))),
            ...$this->decode($adapter->transform(new TextChunk('msg_2', 'Second'))),
            ...$this->decode($adapter->end()),
        ];

        $this->assertSame([
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg_1', 'role' => 'assistant'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg_1', 'delta' => 'First'],
            ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'msg_1'],
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'msg_2', 'role' => 'assistant'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'msg_2', 'delta' => 'Second'],
            ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'msg_2'],
            ['type' => 'RUN_FINISHED', 'threadId' => 'thread_test', 'runId' => 'run_test'],
        ], $events);
    }

    public function test_a_new_message_id_closes_the_open_reasoning(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->transform(new ReasoningChunk('msg_1', 'First thought')), false);

        $events = $this->decode($adapter->transform(new ReasoningChunk('msg_2', 'Second thought')));

        $this->assertSame([
            ['type' => 'REASONING_MESSAGE_END', 'messageId' => 'reasoning_msg_1'],
            ['type' => 'REASONING_END', 'messageId' => 'reasoning_msg_1'],
            ['type' => 'REASONING_START', 'messageId' => 'reasoning_msg_2'],
            ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'reasoning_msg_2', 'role' => 'reasoning'],
            ['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'reasoning_msg_2', 'delta' => 'Second thought'],
        ], $events);
    }

    public function test_reasoning_closes_before_text_of_the_same_message_starts(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->transform(new ReasoningChunk('msg_1', 'Thinking')), false);

        $events = $this->decode($adapter->transform(new TextChunk('msg_1', 'Answer')));

        $this->assertSame(
            ['REASONING_MESSAGE_END', 'REASONING_END', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT'],
            array_column($events, 'type'),
        );
        $this->assertSame('msg_1', $events[2]['messageId']);
    }

    public function test_text_closes_before_reasoning_restarts(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Answer')), false);

        $events = $this->decode($adapter->transform(new ReasoningChunk('msg_1', 'More thinking')));

        $this->assertSame(
            ['TEXT_MESSAGE_END', 'REASONING_START', 'REASONING_MESSAGE_START', 'REASONING_MESSAGE_CONTENT'],
            array_column($events, 'type'),
        );
    }

    public function test_an_interrupt_closes_open_reasoning_before_the_snapshots(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->transform(new ReasoningChunk('msg_1', 'Deleting is risky')), false);

        $events = $this->decode($adapter->interrupt($this->approval('call_1')));

        $this->assertSame(
            ['REASONING_MESSAGE_END', 'REASONING_END', 'STATE_SNAPSHOT', 'MESSAGES_SNAPSHOT', 'RUN_FINISHED'],
            array_column($events, 'type'),
        );
        $this->assertSame(
            [['id' => 'reasoning_msg_1', 'role' => 'reasoning', 'content' => 'Deleting is risky']],
            $events[3]['messages'],
        );
    }

    public function test_the_messages_snapshot_projects_the_whole_streamed_turn(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test', [['id' => 'user_1', 'role' => 'user', 'content' => 'Weather?']]);
        iterator_to_array($adapter->start(), false);
        iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Let me ')), false);
        iterator_to_array($adapter->transform(new TextChunk('msg_1', 'check')), false);
        $call = new ToolCall('weather', 'call_w', ['city' => 'Rome']);
        iterator_to_array($adapter->transform(new ToolCallChunk('msg_1', $call)), false);
        iterator_to_array($adapter->transform(new ToolResultChunk($call->setResult('Sunny'))), false);

        $events = $this->decode($adapter->interrupt($this->approval('call_1')));

        $this->assertSame([
            ['id' => 'user_1', 'role' => 'user', 'content' => 'Weather?'],
            ['id' => 'msg_1', 'role' => 'assistant', 'content' => 'Let me check', 'toolCalls' => [[
                'id' => 'call_w',
                'type' => 'function',
                'function' => ['name' => 'weather', 'arguments' => '{"city":"Rome"}'],
            ]]],
            ['id' => 'result_call_w', 'role' => 'tool', 'toolCallId' => 'call_w', 'content' => 'Sunny'],
        ], $events[1]['messages']);
    }

    public function test_the_state_snapshot_is_an_object_even_when_empty(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');

        $frames = iterator_to_array($adapter->interrupt($this->approval('call_1')), false);

        $this->assertSame('{"type":"STATE_SNAPSHOT","snapshot":{}}', json_encode($frames[0]));
    }

    public function test_tool_result_payload_is_derived_from_the_call(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        $call = (new ToolCall('weather', 'call_w', ['city' => 'Rome']))->setResult('Sunny');

        $events = $this->decode($adapter->transform(new ToolResultChunk($call)));

        $this->assertSame([
            ['type' => 'TOOL_CALL_START', 'toolCallId' => 'call_w', 'toolCallName' => 'weather', 'parentMessageId' => $events[0]['parentMessageId']],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call_w', 'delta' => '{"city":"Rome"}'],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => 'call_w'],
            ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'result_call_w', 'role' => 'tool', 'toolCallId' => 'call_w', 'content' => 'Sunny'],
        ], $events);
        $this->assertStringStartsWith('msg_', $events[0]['parentMessageId']);
    }

    public function test_a_tool_result_is_published_once(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        $call = (new ToolCall('weather', 'call_w'))->setResult('Sunny');
        iterator_to_array($adapter->transform(new ToolResultChunk($call)), false);

        $this->assertSame([], iterator_to_array($adapter->transform(new ToolResultChunk($call)), false));
    }

    public function test_a_seeded_call_publishes_only_its_new_result(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test', [['id' => 'msg_1', 'role' => 'assistant', 'content' => '', 'toolCalls' => [
            ['id' => 'call_w', 'type' => 'function', 'function' => ['name' => 'weather', 'arguments' => '{}']],
        ]]]);
        $call = (new ToolCall('weather', 'call_w'))->setResult('Sunny');

        $events = $this->decode($adapter->transform(new ToolResultChunk($call)));

        $this->assertSame(['TOOL_CALL_RESULT'], array_column($events, 'type'));
        $this->assertSame('call_w', $events[0]['toolCallId']);
    }

    public function test_parallel_calls_of_one_tool_keep_their_own_argument_fragments(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg_1', 'browser', '{"url":"a"}', 'call_a')), false);
        iterator_to_array($adapter->transform(new ToolArgumentChunk('msg_1', 'browser', '{"url":"b"}', 'call_b')), false);
        $request = (new ToolResultsRequest([
            new ToolCall('browser', 'call_a', ['url' => 'a'], deferred: true),
            new ToolCall('browser', 'call_b', ['url' => 'b'], deferred: true),
        ]))->withId(1);

        $events = $this->decode($adapter->interrupt($request));

        $this->assertSame([
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call_a', 'delta' => '{"url":"a"}'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'call_b', 'delta' => '{"url":"b"}'],
        ], array_values(array_filter($events, fn (array $event): bool => $event['type'] === 'TOOL_CALL_ARGS')));
    }

    public function test_a_successful_tool_output_carries_no_error_marker(): void
    {
        $call = (new ToolCall('weather', 'call_w'))->setResult(ToolOutput::text('Sunny'));

        $events = $this->decode((new AGUIAdapter('thread_test'))->transform(new ToolResultChunk($call)));

        $this->assertSame(
            ['type' => 'TOOL_CALL_RESULT', 'messageId' => 'result_call_w', 'role' => 'tool', 'toolCallId' => 'call_w', 'content' => 'Sunny'],
            $events[array_key_last($events)],
        );
    }

    public function test_a_run_without_an_id_never_finishes_with_a_null_run_id(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Hello')), false);

        $this->assertSame([['type' => 'TEXT_MESSAGE_END', 'messageId' => 'msg_1']], $this->decode($adapter->end()));
    }

    public function test_a_call_without_inputs_publishes_an_empty_json_object(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        $call = new ToolCall('browser', 'call_1', deferred: true);

        $events = $this->decode($adapter->interrupt((new ToolResultsRequest([$call]))->withId(1)));

        $this->assertSame('{}', $events[1]['delta']);
    }

    public function test_multibyte_arguments_round_trip_through_the_argument_delta(): void
    {
        $inputs = ['query' => "caffè ☕ \u{1F680} \"quoted\"\nline", 'path' => '../../etc/passwd'];
        $adapter = new AGUIAdapter('thread_test', 'run_test');

        $events = $this->decode($adapter->interrupt((new ToolResultsRequest([new ToolCall('search', 'call_1', $inputs, deferred: true)]))->withId(1)));

        $this->assertSame($inputs, json_decode($events[1]['delta'], true));
    }

    public function test_a_deferred_handoff_publishes_every_pending_call_under_its_parent(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        $a = new ToolCall('browser', 'call_a', ['url' => 'a'], deferred: true);
        $b = new ToolCall('browser', 'call_b', ['url' => 'b'], deferred: true);
        iterator_to_array($adapter->transform(new ToolCallChunk('msg_1', $a)), false);
        iterator_to_array($adapter->transform(new ToolCallChunk('msg_1', $b)), false);

        $events = $this->decode($adapter->interrupt((new ToolResultsRequest([$a, $b]))->withId(1)));

        $starts = array_values(array_filter($events, fn (array $event): bool => $event['type'] === 'TOOL_CALL_START'));
        $this->assertSame(['call_a', 'call_b'], array_column($starts, 'toolCallId'));
        $this->assertSame(['msg_1', 'msg_1'], array_column($starts, 'parentMessageId'));
        $this->assertSame(['type' => 'RUN_FINISHED', 'threadId' => 'thread_test', 'runId' => 'run_test'], $events[array_key_last($events)]);
    }

    public function test_seeded_calls_are_not_dispatched_again(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test', [['id' => 'msg_1', 'role' => 'assistant', 'content' => '', 'toolCalls' => [
            ['id' => 'call_a', 'type' => 'function', 'function' => ['name' => 'browser', 'arguments' => '{}']],
        ]]]);

        $events = $this->decode($adapter->interrupt((new ToolResultsRequest([new ToolCall('browser', 'call_a', deferred: true)]))->withId(2)));

        $this->assertSame(['RUN_FINISHED'], array_column($events, 'type'));
    }

    public function test_an_explicit_interrupt_is_terminal(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->interrupt($this->approval('call_1')), false);

        $this->assertSame([], iterator_to_array($adapter->end(), false));
        $this->assertSame([], iterator_to_array($adapter->interrupt($this->approval('call_1')), false));
        $this->assertSame([], iterator_to_array($adapter->error(new RuntimeException('Late')), false));
        $this->assertSame([], iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Late')), false));
    }

    public function test_a_deferred_handoff_is_terminal(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->interrupt((new ToolResultsRequest([new ToolCall('browser', 'call_a', deferred: true)]))->withId(1)), false);

        $this->assertSame([], iterator_to_array($adapter->end(), false));
        $this->assertSame([], iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Late')), false));
    }

    public function test_start_generates_a_run_id_shared_with_the_finish(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        $events = [...$this->decode($adapter->start()), ...$this->decode($adapter->end())];

        $this->assertSame(['RUN_STARTED', 'RUN_FINISHED'], array_column($events, 'type'));
        $this->assertStringStartsWith('run_', $events[0]['runId']);
        $this->assertSame($events[0]['runId'], $events[1]['runId']);
        $this->assertSame('thread_test', $events[1]['threadId']);
    }

    public function test_frames_survive_key_preserving_collection(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        iterator_to_array($adapter->transform(new ReasoningChunk('msg_1', 'Thinking')), false);

        // Default iterator_to_array() keeps keys: delegated generators must not reuse them.
        $frames = iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Answer')));

        $this->assertSame(
            ['REASONING_MESSAGE_END', 'REASONING_END', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT'],
            array_column($this->decode($frames), 'type'),
        );
    }

    public function test_error_exposes_neither_message_nor_trace_details(): void
    {
        $adapter = new AGUIAdapter('thread_test', 'run_test');
        $secret = 'sk-live-1234 at https://internal.example/v1 in /srv/app/src/Provider.php';

        $frames = iterator_to_array($adapter->error(new RuntimeException($secret, 0, new RuntimeException($secret))), false);

        $this->assertStringNotContainsString('sk-live', json_encode($frames));
        $this->assertStringNotContainsString('/srv/app', json_encode($frames));
    }

    protected function approval(string $callId, ?string $reason = null): ApprovalRequest
    {
        $request = new ApprovalRequest('1 tool call requires approval', [
            new Action($callId, 'geolocation_get', reason: $reason, inputs: ['save' => true]),
        ]);

        return $request->withId(1);
    }

    /**
     * @param iterable<ProtocolEvent> $frames
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $frames): array
    {
        $events = [];
        foreach ($frames as $frame) {
            $events[] = json_decode(json_encode($frame), true);
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    protected function createMockTool(string $name, array $inputs): ToolCall
    {
        return ToolCall::make($name, null, $inputs, 'Mock tool');
    }
}
