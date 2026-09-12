<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Adapters\VercelAIAdapter;
use NeuronAI\Agent\Frontend\AGUIInputTranslator;
use NeuronAI\Agent\Frontend\VercelAIInputTranslator;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Agent\Interrupt\ToolResultsRequest;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ApprovalState;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Workflow\Interrupt\Action;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FrontendToolAdapterTest extends TestCase
{
    /** @param iterable<string> $frames
     * @return list<array<string, mixed>>
     */
    protected function decode(iterable $frames): array
    {
        $events = [];
        foreach ($frames as $frame) {
            if ($frame !== "data: [DONE]\n\n") {
                $events[] = json_decode(substr($frame, 6), true, flags: JSON_THROW_ON_ERROR);
            }
        }
        return $events;
    }

    public function test_vercel_same_name_results_use_their_original_ids_in_a_fresh_stream(): void
    {
        $adapter = new VercelAIAdapter();
        $a = (new ToolCall('browser', 'a'))->setResult('first');
        $b = (new ToolCall('browser', 'b'))->setResult(ToolOutput::error('failed'));
        $events = [...$this->decode($adapter->transform(new ToolResultChunk($b))),
            ...$this->decode($adapter->transform(new ToolResultChunk($a)))];
        $this->assertIsString($events[0]['messageId']);
        $results = array_values(array_filter($events, fn (array $event): bool => str_starts_with($event['type'], 'tool-output-')));
        $this->assertSame(['b', 'a'], array_column($results, 'toolCallId'));
        $this->assertSame('tool-output-error', $results[0]['type']);
        $this->assertSame('failed', $results[0]['errorText']);
        $this->assertNotContains('tool-input-available', array_column($events, 'type'));
    }

    public function test_vercel_dispatch_is_released_only_at_suspension(): void
    {
        $adapter = new VercelAIAdapter();
        $call = new ToolCall('browser', 'a', deferred: true);
        $preview = $this->decode($adapter->transform(new ToolCallChunk($call)));
        $this->assertNotContains('tool-input-available', array_column($preview, 'type'));
        $frames = iterator_to_array($adapter->suspended([(new ToolResultsRequest([$call]))->withId(1)]));
        $events = $this->decode($frames);
        $this->assertSame(['tool-input-available', 'finish'], array_column($events, 'type'));
        $this->assertStringContainsString('"input":{}', $frames[0]);
        $this->assertSame('a', $events[0]['toolCallId']);
    }

    public function test_vercel_preserves_frontend_output_and_assistant_identity_on_resume(): void
    {
        $parts = [['type' => 'tool-browser', 'toolCallId' => 'a', 'state' => 'output-available', 'output' => ['visible' => false]]];
        $adapter = new VercelAIAdapter('assistant-existing', $parts);
        $call = (new ToolCall('browser', 'a', deferred: true))->setResult('{"visible":false}');
        $result = $this->decode($adapter->transform(new ToolResultChunk($call)));
        $this->assertSame([['type' => 'start', 'messageId' => 'assistant-existing']], $result);
        $text = $this->decode($adapter->transform(new TextChunk('new-provider-id', 'Done')));
        $this->assertSame(['start-step', 'text-start', 'text-delta'], array_column($text, 'type'));
    }

    public function test_vercel_partial_continuation_does_not_dispatch_the_remaining_call_twice(): void
    {
        $adapter = new VercelAIAdapter('assistant', [[
            'type' => 'tool-browser', 'toolCallId' => 'b', 'state' => 'input-available', 'input' => [],
        ]]);
        $events = $this->decode($adapter->suspended([(new ToolResultsRequest([new ToolCall('browser', 'b', deferred: true)]))->withId(2)]));
        $this->assertSame(['finish'], array_column($events, 'type'));
    }

    public function test_vercel_approval_frames_round_trip_without_dispatch(): void
    {
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser', inputs: ['selector' => 'h1'])]))->withId(4);
        $events = $this->decode((new VercelAIAdapter())->suspended([$request]));
        $this->assertSame(['start', 'tool-input-start', 'tool-input-delta', 'tool-approval-request', 'finish'], array_column($events, 'type'));
        $inputs = (new VercelAIInputTranslator())->translate(['messages' => [[
            'role' => 'assistant', 'parts' => [[
                'type' => 'tool-browser', 'toolCallId' => $events[3]['toolCallId'], 'state' => 'approval-responded',
                'approval' => ['id' => $events[3]['approvalId'], 'approved' => false],
            ]],
        ]]], [$request]);
        $this->assertSame(['a' => 'reject'], $inputs[0]->payload);
        $call = (new ToolCall('browser', 'a'))->setApprovalState(ApprovalState::Rejected)->setResult('Rejected');
        $result = $this->decode((new VercelAIAdapter())->transform(new ToolResultChunk($call)));
        $this->assertSame('tool-output-denied', $result[array_key_last($result)]['type']);
        $this->assertNotContains('tool-input-available', array_column($result, 'type'));
    }

    public function test_agui_snapshots_preserve_history_reasoning_and_approval_proposals(): void
    {
        $messages = [['id' => 'user', 'role' => 'user', 'content' => 'Read the page']];
        $adapter = new AGUIAdapter('thread', 'run', $messages, ['selection' => 'h1']);
        iterator_to_array($adapter->start());
        iterator_to_array($adapter->transform(new ReasoningChunk('assistant', 'Thinking')));
        iterator_to_array($adapter->transform(new TextChunk('assistant', 'Checking')));
        $request = (new ApprovalRequest('Approve', [new Action('a', 'browser')]))->withId(1);
        $events = $this->decode($adapter->suspended([$request]));
        $snapshots = array_column($events, null, 'type');
        $this->assertSame(['selection' => 'h1'], $snapshots['STATE_SNAPSHOT']['snapshot']);
        $this->assertSame(['user', 'reasoning', 'assistant'], array_column($snapshots['MESSAGES_SNAPSHOT']['messages'], 'role'));
        $interrupt = $snapshots['RUN_FINISHED']['outcome']['interrupts'][0];
        $this->assertSame('confirmation', $interrupt['reason']);
        $this->assertNotContains('TOOL_CALL_START', array_column($events, 'type'));
        $inputs = (new AGUIInputTranslator())->translate(['resume' => [[
            'interruptId' => $interrupt['id'], 'status' => 'resolved', 'payload' => ['approved' => true],
        ]]], [$request]);
        $this->assertSame(['a' => 'approve'], $inputs[0]->payload);
    }

    public function test_agui_does_not_echo_frontend_results_or_repeat_known_calls(): void
    {
        $messages = [
            ['id' => 'assistant', 'role' => 'assistant', 'toolCalls' => [
                ['id' => 'a', 'type' => 'function', 'function' => ['name' => 'browser', 'arguments' => '{}']],
            ]],
            ['id' => 'client-result', 'role' => 'tool', 'toolCallId' => 'a', 'content' => 'Page title'],
        ];
        $adapter = new AGUIAdapter('thread', 'next-run', $messages);
        $call = (new ToolCall('browser', 'a', deferred: true))->setResult('Page title');
        $this->assertSame([], $this->decode($adapter->transform(new ToolResultChunk($call))));
    }

    public function test_agui_errors_have_an_error_marker_and_original_call_id(): void
    {
        $call = (new ToolCall('browser', 'failed'))->setResult(ToolOutput::error('Not available'));
        $events = $this->decode((new AGUIAdapter('thread'))->transform(new ToolResultChunk($call)));
        $result = $events[array_key_last($events)];
        $this->assertSame('failed', $result['toolCallId']);
        $this->assertSame('Not available', $result['error']);
    }

    public function test_agui_mixed_waits_require_explicit_resume_without_premature_dispatch(): void
    {
        $events = $this->decode((new AGUIAdapter('thread', 'run'))->suspended([
            (new ToolResultsRequest([new ToolCall('browser', 'a', deferred: true)]))->withId(1),
            (new WaitForEventRequest('custom'))->withId(2),
        ]));
        $this->assertNotContains('TOOL_CALL_START', array_column($events, 'type'));
        $this->assertSame(['1', '2'], array_column($events[array_key_last($events)]['outcome']['interrupts'], 'id'));
    }

    public function test_success_closes_parts_and_is_terminal_for_both_adapters(): void
    {
        foreach ([new AGUIAdapter('thread', 'run'), new VercelAIAdapter()] as $adapter) {
            $this->decode($adapter->start());
            $this->decode($adapter->transform(new ReasoningChunk('message', 'Thinking')));
            $events = $this->decode($adapter->end());
            $this->assertNotEmpty($events);
            $this->assertSame([], $this->decode($adapter->end()));
            $this->assertSame([], $this->decode($adapter->error(new RuntimeException('Late'))));
            $this->assertSame([], $this->decode($adapter->transform(new TextChunk('message', 'Late'))));
        }
    }
}
