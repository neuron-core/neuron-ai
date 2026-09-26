<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Agent\Adapters;

use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Interrupt\ActionDecision;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function array_column;
use function array_filter;
use function array_slice;
use function array_values;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function array_shift;

class VercelAIAdapterTest extends TestCase
{
    protected VercelAIAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new VercelAIAdapter();
    }

    public function test_get_headers_returns_correct_headers(): void
    {
        $this->assertSame([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
            'x-vercel-ai-ui-message-stream' => 'v1',
        ], $this->adapter->getHeaders());
    }

    public function test_start_returns_empty_array(): void
    {
        $this->assertSame([], $this->adapter->start());
    }

    public function test_end_returns_finish(): void
    {
        $result = iterator_to_array($this->adapter->end());

        $this->assertCount(1, $result);
        $this->assertSame('finish', $result[0]->type);
    }

    public function test_error_emits_error_text(): void
    {
        $message = "Provider failed: \"unavailable\"\nPlease retry.";
        $result = iterator_to_array($this->adapter->error(new RuntimeException($message, 503)), false);

        $this->assertCount(1, $result);
        $this->assertSame([
            'type' => 'error',
            'errorText' => 'The run failed.',
        ], json_decode(json_encode($result[0]), true));
    }

    public function test_error_message_hook_decides_the_wire_text(): void
    {
        $adapter = new class () extends VercelAIAdapter {
            protected function errorMessage(Throwable $error): string
            {
                return 'Visible: ' . $error->getMessage();
            }
        };

        $result = iterator_to_array($adapter->error(new RuntimeException('provider down')), false);

        $this->assertSame('Visible: provider down', $result[0]->data['errorText']);
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
        $events = $this->decode($this->adapter->transform(new ToolCallChunk('msg_123', $tool)));
        $this->assertSame(['text-end', 'tool-input-start', 'tool-input-delta'], array_column($events, 'type'));
        $this->assertSame('calculator', $events[1]['toolName']);
        $this->assertSame('{"operation":"add"}', $events[2]['inputTextDelta']);
    }

    public function test_transform_tool_result_chunk(): void
    {
        // Initialize and call tool first (consume the generators so they execute)
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);
        $tool = $this->createMockTool('calculator', ['operation' => 'add']);
        iterator_to_array($this->adapter->transform(new ToolCallChunk('msg_123', $tool)), false);

        // Now send result
        $tool->setResult('42');
        $chunk = new ToolResultChunk($tool);

        $result = iterator_to_array($this->adapter->transform($chunk), false);

        $this->assertCount(1, $result);
        $this->assertSame('tool-output-available', $result[0]->type);
        $this->assertSame('42', $result[0]->data['output']);
    }

    public function test_transform_tool_argument_chunks_stream_input_deltas(): void
    {
        // Initialize with a text chunk first (consume the generator so it executes)
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);

        $first = iterator_to_array($this->adapter->transform(
            new ToolArgumentChunk('msg_123', 'calculator', '{"operation":', 'call_1')
        ), false);

        $this->assertSame('text-end', array_shift($first)?->type);
        $this->assertCount(2, $first);
        $this->assertSame('tool-input-start', $first[0]->type);
        $this->assertSame('call_1', $first[0]->data['toolCallId']);
        $this->assertSame('calculator', $first[0]->data['toolName']);
        $this->assertSame('tool-input-delta', $first[1]->type);
        $this->assertSame('{"operation":', $first[1]->data['inputTextDelta']);

        $second = iterator_to_array($this->adapter->transform(
            new ToolArgumentChunk('msg_123', 'calculator', '"add"}', 'call_1')
        ), false);

        // Subsequent fragments only emit deltas
        $this->assertCount(1, $second);
        $this->assertSame('tool-input-delta', $second[0]->type);
        $this->assertSame('call_1', $second[0]->data['toolCallId']);
    }

    public function test_tool_call_chunk_reuses_streamed_call_id(): void
    {
        iterator_to_array($this->adapter->transform(new ToolArgumentChunk('msg_123', 'calculator', '{}', 'call_1')), false);
        $tool = $this->createMockTool('calculator', []);
        $this->assertSame([], iterator_to_array($this->adapter->transform(new ToolCallChunk('msg_123', $tool)), false));
        $tool->setResult('42');
        $events = $this->decode($this->adapter->transform(new ToolResultChunk($tool)));
        $this->assertSame('call_1', $events[0]['toolCallId']);
    }

    public function test_message_and_part_ids_are_consistent_across_chunks(): void
    {
        $first = $this->decode($this->adapter->transform(new TextChunk('msg_123', 'Hello')));
        $second = $this->decode($this->adapter->transform(new TextChunk('msg_123', ' world')));
        $end = $this->decode($this->adapter->end());
        $this->assertSame('msg_123', $first[0]['messageId']);
        $this->assertSame(['text-delta'], array_column($second, 'type'));
        $this->assertSame($first[1]['id'], $second[0]['id']);
        $this->assertSame(['text-end', 'finish'], array_column($end, 'type'));
        $this->assertSame($first[1]['id'], $end[0]['id']);
    }

    public function test_transform_yields_protocol_events(): void
    {
        iterator_to_array($this->adapter->transform(new ReasoningChunk('msg_123', 'Thinking')), false);

        // Default iterator_to_array() keeps keys: delegated generators must not reuse them.
        $result = iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'Test')));

        $this->assertContainsOnlyInstancesOf(ProtocolEvent::class, $result);
        $this->assertSame(['reasoning-end', 'text-start', 'text-delta'], array_column($this->decode($result), 'type'));
    }

    public function test_suspended_requests_approval_without_dispatching_the_tool(): void
    {
        iterator_to_array($this->adapter->transform(new ToolArgumentChunk('msg_123', 'delete_file', '{"path":"/tmp/x"}', 'call_1')), false);
        $request = (new ApprovalRequest('Approve', [
            new Action('call_1', 'delete_file', reason: 'Consent required', inputs: ['path' => '/tmp/x']),
        ]))->withId(1);
        $frames = iterator_to_array($this->adapter->interrupt($request), false);

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

        $frames = iterator_to_array($this->adapter->interrupt($request), false);

        $events = $this->decode($frames);
        $this->assertSame(['start', 'tool-input-start', 'tool-input-delta', 'tool-approval-request', 'finish'], array_column($events, 'type'));
        // Without a per-action reason the request message is the prompt.
        $this->assertSame('1 tool call requires approval', $events[3]['reason']);
    }

    public function test_suspended_encodes_other_requests_as_transient_data(): void
    {
        $request = (new WaitForEventRequest('order.approved'))->withId(3);

        $frames = iterator_to_array($this->adapter->interrupt($request), false);

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
        $this->assertSame([], iterator_to_array($this->adapter->interrupt($request), false));
    }

    public function test_a_new_message_id_opens_a_new_text_part(): void
    {
        $first = $this->decode($this->adapter->transform(new TextChunk('msg_1', 'First')));
        $second = $this->decode($this->adapter->transform(new TextChunk('msg_2', 'Second')));

        $this->assertSame(['text-end', 'text-start', 'text-delta'], array_column($second, 'type'));
        $this->assertSame($first[1]['id'], $second[0]['id']);
        $this->assertNotSame($first[1]['id'], $second[1]['id']);
        $this->assertStringStartsWith('text_', $second[1]['id']);
        $this->assertSame('msg_1', $first[0]['messageId'], 'The UI message keeps the first provider message ID.');
    }

    public function test_reasoning_closes_before_text_starts(): void
    {
        $reasoning = $this->decode($this->adapter->transform(new ReasoningChunk('msg_1', 'Thinking')));
        $text = $this->decode($this->adapter->transform(new TextChunk('msg_1', 'Answer')));

        $this->assertSame(['start', 'reasoning-start', 'reasoning-delta'], array_column($reasoning, 'type'));
        $this->assertStringStartsWith('reasoning_', $reasoning[1]['id']);
        $this->assertSame([
            ['type' => 'reasoning-end', 'id' => $reasoning[1]['id']],
            ['type' => 'text-start', 'id' => $text[1]['id']],
            ['type' => 'text-delta', 'id' => $text[1]['id'], 'delta' => 'Answer'],
        ], $text);
    }

    public function test_empty_deltas_open_no_parts(): void
    {
        $events = [
            ...$this->decode($this->adapter->transform(new TextChunk('msg_1', ''))),
            ...$this->decode($this->adapter->transform(new ReasoningChunk('msg_1', ''))),
            ...$this->decode($this->adapter->end()),
        ];

        $this->assertSame([['type' => 'start', 'messageId' => 'msg_1'], ['type' => 'finish']], $events);
    }

    public function test_each_inference_after_tool_results_is_a_new_step(): void
    {
        $first = (new ToolCall('search', 'call_1'))->setResult('one');
        $second = (new ToolCall('search', 'call_2'))->setResult('two');

        $events = [
            ...$this->decode($this->adapter->transform(new ToolResultChunk($first))),
            ...$this->decode($this->adapter->transform(new ReasoningChunk('msg_2', 'Again'))),
            ...$this->decode($this->adapter->transform(new ToolResultChunk($second))),
            ...$this->decode($this->adapter->transform(new ToolArgumentChunk('msg_3', 'search', '{}', 'call_3'))),
            ...$this->decode($this->adapter->end()),
        ];

        $this->assertSame([
            'start', 'tool-input-start', 'tool-input-delta', 'tool-output-available',
            'start-step', 'reasoning-start', 'reasoning-delta',
            'reasoning-end', 'tool-input-start', 'tool-input-delta', 'tool-output-available',
            'finish-step', 'start-step', 'tool-input-start', 'tool-input-delta',
            'finish-step', 'finish',
        ], array_column($events, 'type'));
    }

    public function test_an_empty_delta_after_tool_results_still_starts_the_step_once(): void
    {
        iterator_to_array($this->adapter->transform(new ToolResultChunk((new ToolCall('search', 'call_1'))->setResult('one'))), false);

        $empty = $this->decode($this->adapter->transform(new TextChunk('msg_2', '')));
        $text = $this->decode($this->adapter->transform(new TextChunk('msg_2', 'Done')));

        $this->assertSame([['type' => 'start-step']], $empty);
        $this->assertSame(['text-start', 'text-delta'], array_column($text, 'type'));
    }

    public function test_a_continuation_keeps_the_assistant_message_id(): void
    {
        $adapter = new VercelAIAdapter('msg_previous');

        $events = $this->decode($adapter->transform(new TextChunk('msg_provider', 'Done')));

        $this->assertSame(['type' => 'start', 'messageId' => 'msg_previous'], $events[0]);
    }

    public function test_an_unmapped_object_neither_emits_nor_starts_the_message(): void
    {
        $this->assertSame([], iterator_to_array($this->adapter->transform(new stdClass()), false));

        $events = $this->decode($this->adapter->transform(new TextChunk('msg_1', 'Hello')));

        $this->assertSame(['type' => 'start', 'messageId' => 'msg_1'], $events[0]);
    }

    public function test_a_tool_call_closes_text_streamed_after_its_preview(): void
    {
        iterator_to_array($this->adapter->transform(new ToolArgumentChunk('msg_1', 'search', '{}', 'call_1')), false);
        $text = $this->decode($this->adapter->transform(new TextChunk('msg_1', 'Searching')));

        $events = $this->decode($this->adapter->transform(new ToolCallChunk('msg_1', new ToolCall('search', 'call_1'))));

        $this->assertSame([['type' => 'text-end', 'id' => $text[0]['id']]], $events);
    }

    public function test_argument_fragments_without_a_call_id_share_the_id_of_their_tool(): void
    {
        $events = [
            ...$this->decode($this->adapter->transform(new ToolArgumentChunk('msg_1', 'search', '{"q":'))),
            ...$this->decode($this->adapter->transform(new ToolArgumentChunk('msg_1', 'search', '"x"}'))),
        ];

        $this->assertSame(['start', 'tool-input-start', 'tool-input-delta', 'tool-input-delta'], array_column($events, 'type'));
        $this->assertStringStartsWith('call_', $events[1]['toolCallId']);
        $this->assertSame([$events[1]['toolCallId'], $events[1]['toolCallId']], [$events[2]['toolCallId'], $events[3]['toolCallId']]);
    }

    /** @return iterable<string, array{ToolCall, array<string, mixed>}> */
    public static function settledResults(): iterable
    {
        yield 'output' => [
            (new ToolCall('search', 'call_1'))->setResult('Found'),
            ['type' => 'tool-output-available', 'toolCallId' => 'call_1', 'output' => 'Found'],
        ];
        yield 'tool output' => [
            (new ToolCall('search', 'call_1'))->setResult(ToolOutput::text('Found')),
            ['type' => 'tool-output-available', 'toolCallId' => 'call_1', 'output' => 'Found'],
        ];
        yield 'error' => [
            (new ToolCall('search', 'call_1'))->setResult(ToolOutput::error('Index offline')),
            ['type' => 'tool-output-error', 'toolCallId' => 'call_1', 'errorText' => 'Index offline'],
        ];
        yield 'rejection' => [
            (new ToolCall('search', 'call_1'))->setApprovalState(ApprovalState::Rejected)->setResult(ToolOutput::error('Rejected')),
            ['type' => 'tool-output-denied', 'toolCallId' => 'call_1'],
        ];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('settledResults')]
    public function test_a_local_result_settles_its_preview(ToolCall $call, array $expected): void
    {
        $events = $this->decode($this->adapter->transform(new ToolResultChunk($call)));

        $this->assertSame(['start', 'tool-input-start', 'tool-input-delta'], array_column(array_slice($events, 0, 3), 'type'));
        $this->assertSame($expected, $events[3]);
        $this->assertCount(4, $events);
    }

    public function test_a_tool_result_is_published_once(): void
    {
        $call = (new ToolCall('search', 'call_1'))->setResult('Found');
        iterator_to_array($this->adapter->transform(new ToolResultChunk($call)), false);

        $this->assertSame([], iterator_to_array($this->adapter->transform(new ToolResultChunk($call)), false));
    }

    public function test_a_deferred_handoff_dispatches_calls_that_were_never_previewed(): void
    {
        $call = new ToolCall('browser', 'call_1', ['selector' => 'h1'], deferred: true);

        $events = $this->decode($this->adapter->interrupt((new ToolResultsRequest([$call]))->withId(1)));

        $this->assertSame(['start', 'tool-input-start', 'tool-input-delta', 'tool-input-available', 'finish'], array_column($events, 'type'));
        $this->assertSame(['type' => 'tool-input-available', 'toolCallId' => 'call_1', 'toolName' => 'browser', 'input' => ['selector' => 'h1']], $events[3]);
    }

    public function test_an_interrupt_closes_the_open_text_part(): void
    {
        $text = $this->decode($this->adapter->transform(new TextChunk('msg_1', 'Opening')));

        $events = $this->decode($this->adapter->interrupt((new WaitForEventRequest('order.approved'))->withId(1)));

        $this->assertSame(['type' => 'text-end', 'id' => $text[1]['id']], $events[0]);
        $this->assertSame(['text-end', 'data-workflow-interrupt', 'finish'], array_column($events, 'type'));
    }

    /** @return iterable<string, array{string}> */
    public static function settledStates(): iterable
    {
        yield 'output available' => ['output-available'];
        yield 'output error' => ['output-error'];
        yield 'output denied' => ['output-denied'];
    }

    #[DataProvider('settledStates')]
    public function test_calls_the_client_already_settled_are_not_dispatched(string $state): void
    {
        $adapter = new VercelAIAdapter('assistant', [['type' => 'tool-browser', 'toolCallId' => 'call_1', 'state' => $state]]);
        $request = (new ToolResultsRequest([
            new ToolCall('browser', 'call_1', deferred: true),
            new ToolCall('browser', 'call_2', deferred: true),
        ]))->withId(1);

        $events = $this->decode($adapter->interrupt($request));

        $this->assertSame(['call_2'], array_column(array_filter($events, fn (array $event): bool => $event['type'] === 'tool-input-available'), 'toolCallId'));
    }

    public function test_decided_actions_repeat_their_decision_beside_the_request(): void
    {
        $request = (new ApprovalRequest('Approve', [
            new Action('call_a', 'delete_file', decision: ActionDecision::Approved),
            new Action('call_b', 'send_email', decision: ActionDecision::Rejected, feedback: 'Too many recipients'),
            new Action('call_c', 'read_file'),
        ]))->withId(1);

        $events = $this->decode($this->adapter->interrupt($request));

        $responses = array_values(array_filter($events, fn (array $event): bool => $event['type'] === 'tool-approval-response'));
        $this->assertSame([
            ['type' => 'tool-approval-response', 'approvalId' => 'call_a', 'approved' => true],
            ['type' => 'tool-approval-response', 'approvalId' => 'call_b', 'approved' => false, 'reason' => 'Too many recipients'],
        ], $responses);
        $this->assertSame(['call_a', 'call_b', 'call_c'], array_column(
            array_filter($events, fn (array $event): bool => $event['type'] === 'tool-approval-request'),
            'toolCallId',
        ));
        $this->assertNotContains('tool-input-available', array_column($events, 'type'));
    }

    public function test_an_interrupt_is_terminal(): void
    {
        iterator_to_array($this->adapter->interrupt((new WaitForEventRequest('order.approved'))->withId(1)), false);

        $this->assertSame([], iterator_to_array($this->adapter->end(), false));
        $this->assertSame([], iterator_to_array($this->adapter->error(new RuntimeException('Late')), false));
        $this->assertSame([], iterator_to_array($this->adapter->transform(new TextChunk('msg_1', 'Late')), false));
        $this->assertSame([], iterator_to_array($this->adapter->interrupt((new WaitForEventRequest('again'))->withId(2)), false));
    }

    public function test_error_closes_open_parts_and_never_finishes(): void
    {
        $text = $this->decode($this->adapter->transform(new TextChunk('msg_1', 'Hello')));

        $events = $this->decode($this->adapter->error(new RuntimeException('sk-secret at /srv/app')));

        $this->assertSame([
            ['type' => 'text-end', 'id' => $text[1]['id']],
            ['type' => 'error', 'errorText' => 'The run failed.'],
        ], $events);
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
