<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Chat\Messages\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\ActivityStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\CustomStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepFinishedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Adapters\Events\StepStartedStreamEvent;
use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Tools\ToolCall;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function substr;
use function array_key_last;
use function array_column;

/**
 * Validates that {@see AGUIAdapter} emits an event stream compliant with the
 * AG-UI protocol.
 *
 * Presence of individual events is covered by AgUIAdapterTest. This suite
 * enforces the protocol invariants: required fields per event type, matching
 * START/CONTENT/END identifiers, non-empty content deltas, and the mandatory
 * run lifecycle boundaries.
 *
 * @see https://docs.ag-ui.com/concepts/events
 */
class AgUIProtocolComplianceTest extends TestCase
{
    /**
     * Required fields for every event type the adapter can emit.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_FIELDS = [
        'RUN_STARTED' => ['runId', 'threadId'],
        'RUN_FINISHED' => ['runId', 'threadId'],
        'STEP_STARTED' => ['stepName'],
        'STEP_FINISHED' => ['stepName'],
        'TEXT_MESSAGE_START' => ['messageId', 'role'],
        'TEXT_MESSAGE_CONTENT' => ['messageId', 'delta'],
        'TEXT_MESSAGE_END' => ['messageId'],
        'REASONING_START' => ['messageId'],
        'REASONING_MESSAGE_START' => ['messageId', 'role'],
        'REASONING_MESSAGE_CONTENT' => ['messageId', 'delta'],
        'REASONING_MESSAGE_END' => ['messageId'],
        'REASONING_END' => ['messageId'],
        'TOOL_CALL_START' => ['toolCallId', 'toolCallName'],
        'TOOL_CALL_ARGS' => ['toolCallId', 'delta'],
        'TOOL_CALL_END' => ['toolCallId'],
        'TOOL_CALL_RESULT' => ['toolCallId', 'messageId', 'content'],
        'ACTIVITY_SNAPSHOT' => ['messageId', 'activityType', 'content'],
        'CUSTOM' => ['name', 'value'],
    ];

    public function test_get_headers_returns_correct_headers(): void
    {
        $headers = (new AGUIAdapter('thread_test'))->getHeaders();

        $this->assertSame('text/event-stream', $headers['Content-Type'] ?? null);
        $this->assertSame('no-cache', $headers['Cache-Control'] ?? null);
        $this->assertSame('keep-alive', $headers['Connection'] ?? null);
    }

    public function test_text_only_flow_is_compliant(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        $events = $this->collect(
            $adapter->start(),
            $adapter->transform(new TextChunk('msg_1', 'Hello')),
            $adapter->transform(new TextChunk('msg_1', ' world')),
            $adapter->end(),
        );

        $this->assertCompliant($events);
    }

    public function test_reasoning_then_text_flow_is_compliant(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        $events = $this->collect(
            $adapter->start(),
            $adapter->transform(new ReasoningChunk('reason_1', 'Thinking...')),
            $adapter->transform(new TextChunk('msg_1', 'Answer')),
            $adapter->end(),
        );

        $this->assertCompliant($events);
    }

    public function test_tool_call_flow_is_compliant(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        $tool = $this->makeTool('search', ['query' => 'test']);

        $callEvents = iterator_to_array($adapter->transform(new ToolCallChunk($tool)));
        $tool->setResult('Found 5 results');
        $resultEvents = iterator_to_array($adapter->transform(new ToolResultChunk($tool)));

        $events = $this->collect(
            $adapter->start(),
            $callEvents,
            $resultEvents,
            $adapter->end(),
        );

        $this->assertCompliant($events);
    }

    public function test_full_mixed_flow_is_compliant(): void
    {
        $adapter = new AGUIAdapter('thread_test');
        $tool = $this->makeTool('calculator', ['x' => 5, 'y' => 3]);

        $start = iterator_to_array($adapter->start());
        $text = iterator_to_array($adapter->transform(new TextChunk('msg_1', 'Let me compute')));
        $reasoning = iterator_to_array($adapter->transform(new ReasoningChunk('reason_1', 'Adding numbers')));
        $call = iterator_to_array($adapter->transform(new ToolCallChunk($tool)));
        $tool->setResult('8');
        $result = iterator_to_array($adapter->transform(new ToolResultChunk($tool)));
        $more = iterator_to_array($adapter->transform(new TextChunk('msg_2', 'The answer is 8')));
        $end = iterator_to_array($adapter->end());

        $events = $this->collect($start, $text, $reasoning, $call, $result, $more, $end);

        $this->assertCompliant($events);
    }

    public function test_empty_deltas_are_skipped(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        $events = $this->collect(
            $adapter->start(),
            $adapter->transform(new TextChunk('msg_1', '')),
            $adapter->transform(new ReasoningChunk('reason_1', '')),
            $adapter->transform(new TextChunk('msg_1', 'Hello')),
            $adapter->end(),
        );

        $this->assertCompliant($events);

        $types = array_column($events, 'type');
        $this->assertSame(
            ['RUN_STARTED', 'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END', 'RUN_FINISHED'],
            $types,
            'Empty deltas must not produce any event'
        );
    }

    public function test_client_provided_ids_are_echoed(): void
    {
        $adapter = new AGUIAdapter('thread_custom', 'run_custom');

        $events = $this->collect(
            $adapter->start(),
            $adapter->transform(new TextChunk('msg_1', 'Hello')),
            $adapter->end(),
        );

        $this->assertCompliant($events);

        $this->assertSame('thread_custom', $events[0]['threadId']);
        $this->assertSame('run_custom', $events[0]['runId']);

        $last = $events[array_key_last($events)];
        $this->assertSame('thread_custom', $last['threadId']);
        $this->assertSame('run_custom', $last['runId']);
    }

    public function test_portable_events_are_compliant_run_level_events(): void
    {
        $adapter = new AGUIAdapter('thread_test');

        $events = $this->collect(
            $adapter->start(),
            $adapter->transform(new StepStartedStreamEvent('indexing')),
            $adapter->transform(new ActivityStreamEvent(
                id: 'job-1',
                type: 'indexing',
                data: ['processed' => 5],
            )),
            $adapter->transform(new CustomStreamEvent('notice', ['message' => 'Working'])),
            $adapter->transform(new StepFinishedStreamEvent('indexing')),
            $adapter->end(),
        );

        $this->assertCompliant($events);
        $this->assertSame([
            'RUN_STARTED',
            'STEP_STARTED',
            'ACTIVITY_SNAPSHOT',
            'CUSTOM',
            'STEP_FINISHED',
            'RUN_FINISHED',
        ], array_column($events, 'type'));
    }

    /**
     * Parse SSE frames into decoded event payloads.
     *
     * @param iterable<string> ...$streams
     * @return list<array<string, mixed>>
     */
    private function collect(iterable ...$streams): array
    {
        $events = [];
        foreach ($streams as $stream) {
            foreach ($stream as $frame) {
                $this->assertTrue(str_starts_with($frame, 'data: '), "SSE frame must start with 'data: '");
                $this->assertTrue(str_ends_with($frame, "\n\n"), 'SSE frame must end with a blank line');

                $decoded = json_decode(substr($frame, 6, -2), true);
                $this->assertIsArray($decoded, 'SSE payload must be valid JSON object');
                $this->assertArrayHasKey('type', $decoded, 'Every event must carry a type');

                $events[] = $decoded;
            }
        }

        return $events;
    }

    /**
     * Assert an event stream honours the AG-UI protocol invariants.
     *
     * @param list<array<string, mixed>> $events
     */
    private function assertCompliant(array $events): void
    {
        $this->assertNotEmpty($events, 'Stream must contain at least the run lifecycle events');

        $first = $events[0];
        $this->assertSame('RUN_STARTED', $first['type'], 'First event must be RUN_STARTED');
        $this->assertSame('RUN_FINISHED', $events[array_key_last($events)]['type'], 'Last event must be RUN_FINISHED');

        $openText = null;
        $openReasoning = null;
        $openReasoningMessage = null;
        $toolArgsOpen = [];
        $startedTools = [];
        $runId = null;
        $threadId = null;

        foreach ($events as $event) {
            $type = $event['type'];
            $this->assertArrayHasKey($type, self::REQUIRED_FIELDS, sprintf('Unknown event type "%s"', $type));

            foreach (self::REQUIRED_FIELDS[$type] as $field) {
                $this->assertArrayHasKey($field, $event, sprintf('%s must include "%s"', $type, $field));
                $this->assertNotNull($event[$field], sprintf('%s.%s must not be null', $type, $field));
            }

            switch ($type) {
                case 'RUN_STARTED':
                    $runId = $event['runId'];
                    $threadId = $event['threadId'];
                    break;

                case 'RUN_FINISHED':
                    $this->assertSame($runId, $event['runId'], 'RUN_FINISHED.runId must match RUN_STARTED');
                    $this->assertSame($threadId, $event['threadId'], 'RUN_FINISHED.threadId must match RUN_STARTED');
                    break;

                case 'TEXT_MESSAGE_START':
                    $this->assertNull($openText, 'A text message is already open; nested TEXT_MESSAGE_START is invalid');
                    $this->assertSame('assistant', $event['role']);
                    $openText = $event['messageId'];
                    break;

                case 'TEXT_MESSAGE_CONTENT':
                    $this->assertSame($openText, $event['messageId'], 'TEXT_MESSAGE_CONTENT outside its START/END pair');
                    $this->assertContentDelta($type, $event['delta']);
                    break;

                case 'TEXT_MESSAGE_END':
                    $this->assertSame($openText, $event['messageId'], 'TEXT_MESSAGE_END must match the open START');
                    $openText = null;
                    break;

                case 'REASONING_START':
                    $this->assertNull($openReasoning, 'Nested REASONING_START is invalid');
                    $openReasoning = $event['messageId'];
                    break;

                case 'REASONING_MESSAGE_START':
                    $this->assertSame($openReasoning, $event['messageId'], 'REASONING_MESSAGE_START outside REASONING_START');
                    $this->assertSame('reasoning', $event['role']);
                    $openReasoningMessage = $event['messageId'];
                    break;

                case 'REASONING_MESSAGE_CONTENT':
                    $this->assertSame($openReasoningMessage, $event['messageId'], 'REASONING_MESSAGE_CONTENT outside its message');
                    $this->assertContentDelta($type, $event['delta']);
                    break;

                case 'REASONING_MESSAGE_END':
                    $this->assertSame($openReasoningMessage, $event['messageId'], 'REASONING_MESSAGE_END must match its START');
                    $openReasoningMessage = null;
                    break;

                case 'REASONING_END':
                    $this->assertSame($openReasoning, $event['messageId'], 'REASONING_END must match REASONING_START');
                    $this->assertNull($openReasoningMessage, 'REASONING_MESSAGE_END must precede REASONING_END');
                    $openReasoning = null;
                    break;

                case 'TOOL_CALL_START':
                    $id = $event['toolCallId'];
                    $this->assertArrayNotHasKey($id, $startedTools, 'Duplicate TOOL_CALL_START for the same id');
                    $startedTools[$id] = true;
                    $toolArgsOpen[$id] = true;
                    break;

                case 'TOOL_CALL_ARGS':
                    $id = $event['toolCallId'];
                    $this->assertArrayHasKey($id, $toolArgsOpen, 'TOOL_CALL_ARGS before its START or after its END');
                    $this->assertContentDelta($type, $event['delta']);
                    break;

                case 'TOOL_CALL_END':
                    $id = $event['toolCallId'];
                    $this->assertArrayHasKey($id, $toolArgsOpen, 'TOOL_CALL_END without a matching START');
                    unset($toolArgsOpen[$id]);
                    break;

                case 'TOOL_CALL_RESULT':
                    $this->assertArrayHasKey($event['toolCallId'], $startedTools, 'TOOL_CALL_RESULT for an unknown tool call');
                    $this->assertArrayNotHasKey($event['toolCallId'], $toolArgsOpen, 'TOOL_CALL_END must precede TOOL_CALL_RESULT');
                    break;
            }
        }

        $this->assertNull($openText, 'Text message was left open (missing TEXT_MESSAGE_END)');
        $this->assertNull($openReasoning, 'Reasoning context was left open (missing REASONING_END)');
        $this->assertNull($openReasoningMessage, 'Reasoning message was left open (missing REASONING_MESSAGE_END)');
        $this->assertEmpty($toolArgsOpen, 'A tool call was left open (missing TOOL_CALL_END)');
    }

    private function assertContentDelta(string $type, mixed $delta): void
    {
        $this->assertIsString($delta, sprintf('%s.delta must be a string', $type));
        $this->assertNotSame('', $delta, sprintf('%s.delta must be non-empty', $type));
    }

    /**
     * @param array<string, mixed> $inputs
     */
    private function makeTool(string $name, array $inputs): ToolCall
    {
        return ToolCall::make($name, null, $inputs, 'Mock tool');
    }
}
