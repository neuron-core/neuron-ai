<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Adapters;

use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Chat\Messages\Stream\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\TextChunk;
use NeuronAI\Chat\Messages\Stream\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\ToolResultChunk;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class AgUIAdapterTest extends TestCase
{
    private AGUIAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new AGUIAdapter();
    }

    public function test_get_headers_returns_correct_headers(): void
    {
        $headers = $this->adapter->getHeaders();

        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('text/event-stream', $headers['Content-Type']);
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertEquals('no-cache', $headers['Cache-Control']);
        $this->assertArrayHasKey('Connection', $headers);
        $this->assertEquals('keep-alive', $headers['Connection']);
    }

    public function test_start_emits_run_started_event(): void
    {
        $result = \iterator_to_array($this->adapter->start());

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"RunStarted"', $result[0]);
        $this->assertStringContainsString('"runId":"run_', $result[0]);
        $this->assertStringContainsString('"threadId":"thread_', $result[0]);
        $this->assertStringContainsString('"timestamp":', $result[0]);
    }

    public function test_end_emits_text_message_end_and_run_finished(): void
    {
        // Start the run first (consume the generator)
        \iterator_to_array($this->adapter->start());

        // Start a message (consume the generator)
        \iterator_to_array($this->adapter->transform(new TextChunk('Hello')));

        $result = \iterator_to_array($this->adapter->end());

        $this->assertGreaterThanOrEqual(1, \count($result));

        // Should contain TextMessageEnd
        $endEvent = \implode('', $result);
        $this->assertStringContainsString('"type":"TextMessageEnd"', $endEvent);
        $this->assertStringContainsString('"type":"RunFinished"', $endEvent);
    }

    public function test_transform_text_chunk_emits_start_and_content(): void
    {
        $adapter = new AGUIAdapter();
        $chunk = new TextChunk('Hello world');

        $result = [];
        foreach ($adapter->transform($chunk) as $item) {
            $result[] = $item;
        }

        // Should have 2 messages: TextMessageStart + TextMessageContent
        $this->assertCount(2, $result);

        // First output should be TextMessageStart
        $this->assertStringContainsString('"type":"TextMessageStart"', $result[0]);
        $this->assertStringContainsString('"messageId":"msg_', $result[0]);
        $this->assertStringContainsString('"role":"assistant"', $result[0]);

        // Second output should be TextMessageContent
        $this->assertStringContainsString('"type":"TextMessageContent"', $result[1]);
        $this->assertStringContainsString('"delta":"Hello world"', $result[1]);
    }

    public function test_transform_multiple_text_chunks_share_same_message_id(): void
    {
        $adapter = new AGUIAdapter();

        $result1 = \iterator_to_array($adapter->transform(new TextChunk('Hello')));
        $result2 = \iterator_to_array($adapter->transform(new TextChunk(' world')));

        // Extract message ID from first result (TextMessageStart)
        \preg_match('/"messageId":"([^"]+)"/', $result1[0], $matches1);
        $messageId1 = $matches1[1] ?? null;

        // Extract message ID from second result (TextMessageContent)
        \preg_match('/"messageId":"([^"]+)"/', $result2[0], $matches2);
        $messageId2 = $matches2[1] ?? null;

        $this->assertNotNull($messageId1);
        $this->assertNotNull($messageId2);
        $this->assertEquals($messageId1, $messageId2);
    }

    public function test_transform_reasoning_chunk(): void
    {
        $chunk = new ReasoningChunk('Analyzing the problem...');
        $result = \iterator_to_array($this->adapter->transform($chunk));

        // Should emit ReasoningStart and ReasoningMessageContent
        $this->assertGreaterThanOrEqual(2, \count($result));

        $output = \implode('', $result);
        $this->assertStringContainsString('"type":"ReasoningStart"', $output);
        $this->assertStringContainsString('"type":"ReasoningMessageContent"', $output);
        $this->assertStringContainsString('"delta":"Analyzing the problem..."', $output);
    }

    public function test_transform_tool_call_chunk(): void
    {
        // Initialize with a text chunk first
        $this->adapter->transform(new TextChunk('init'));

        $tool = $this->createMockTool('calculator', ['operation' => 'add', 'x' => 5, 'y' => 3]);
        $chunk = new ToolCallChunk([$tool]);

        $result = \iterator_to_array($this->adapter->transform($chunk));

        // Should emit: ToolCallStart, ToolCallArgs, ToolCallEnd
        $this->assertGreaterThanOrEqual(3, \count($result));

        $output = \implode('', $result);
        $this->assertStringContainsString('"type":"ToolCallStart"', $output);
        $this->assertStringContainsString('"toolCallName":"calculator"', $output);
        $this->assertStringContainsString('"type":"ToolCallArgs"', $output);
        // Note: JSON is encoded again, so we need to check for escaped quotes
        $this->assertStringContainsString('operation', $output);
        $this->assertStringContainsString('add', $output);
        $this->assertStringContainsString('"type":"ToolCallEnd"', $output);
    }

    public function test_transform_tool_result_chunk(): void
    {
        // Initialize and call tool first
        $this->adapter->transform(new TextChunk('init'));
        $tool = $this->createMockTool('calculator', ['operation' => 'add']);
        $this->adapter->transform(new ToolCallChunk([$tool]));

        // Now send result
        $tool->setResult('42');
        $chunk = new ToolResultChunk([$tool]);

        $result = \iterator_to_array($this->adapter->transform($chunk));

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"ToolCallResult"', $result[0]);
        $this->assertStringContainsString('"content":"42"', $result[0]);
        $this->assertStringContainsString('"role":"tool"', $result[0]);
    }

    public function test_tool_call_ids_are_consistent(): void
    {
        $adapter = new AGUIAdapter();
        $adapter->transform(new TextChunk('init'));

        $tool = $this->createMockTool('calculator', ['operation' => 'add']);

        // Call the tool
        $callResult = \iterator_to_array($adapter->transform(new ToolCallChunk([$tool])));
        \preg_match('/"toolCallId":"([^"]+)"/', $callResult[0], $callMatches);
        $toolCallId = $callMatches[1] ?? null;

        // Return result for the same tool
        $tool->setResult('42');
        $resultResult = \iterator_to_array($adapter->transform(new ToolResultChunk([$tool])));
        \preg_match('/"toolCallId":"([^"]+)"/', $resultResult[0], $resultMatches);
        $resultToolCallId = $resultMatches[1] ?? null;

        $this->assertNotNull($toolCallId);
        $this->assertNotNull($resultToolCallId);
        $this->assertEquals($toolCallId, $resultToolCallId);
    }

    public function test_sse_format_is_correct(): void
    {
        $chunk = new TextChunk('Test');
        $result = \iterator_to_array($this->adapter->transform($chunk));

        foreach ($result as $line) {
            $this->assertStringStartsWith('data: ', $line);
            $this->assertStringEndsWith("\n\n", $line);

            // Extract JSON and validate
            $json = \substr($line, 6, -2); // Remove "data: " and "\n\n"
            $decoded = \json_decode($json, true);
            $this->assertNotNull($decoded);
            $this->assertArrayHasKey('type', $decoded);
            $this->assertArrayHasKey('timestamp', $decoded);
        }
    }

    public function test_constructor_accepts_custom_thread_id(): void
    {
        $adapter = new AGUIAdapter('custom_thread_123');
        $result = \iterator_to_array($adapter->start());

        $this->assertStringContainsString('"threadId":"custom_thread_123"', $result[0]);
    }

    public function test_full_conversation_flow(): void
    {
        $adapter = new AGUIAdapter();

        // Collect all events
        $allEvents = [];

        // Start
        foreach ($adapter->start() as $event) {
            $allEvents[] = $event;
        }

        // Text message
        foreach ($adapter->transform(new TextChunk('Hello')) as $event) {
            $allEvents[] = $event;
        }

        // Reasoning
        foreach ($adapter->transform(new ReasoningChunk('Thinking...')) as $event) {
            $allEvents[] = $event;
        }

        // Tool call
        $tool = $this->createMockTool('search', ['query' => 'test']);
        foreach ($adapter->transform(new ToolCallChunk([$tool])) as $event) {
            $allEvents[] = $event;
        }

        // Tool result
        $tool->setResult('Found 5 results');
        foreach ($adapter->transform(new ToolResultChunk([$tool])) as $event) {
            $allEvents[] = $event;
        }

        // End
        foreach ($adapter->end() as $event) {
            $allEvents[] = $event;
        }

        // Verify we have all expected event types
        $output = \implode('', $allEvents);
        $this->assertStringContainsString('"type":"RunStarted"', $output);
        $this->assertStringContainsString('"type":"TextMessageStart"', $output);
        $this->assertStringContainsString('"type":"TextMessageContent"', $output);
        $this->assertStringContainsString('"type":"ReasoningStart"', $output);
        $this->assertStringContainsString('"type":"ToolCallStart"', $output);
        $this->assertStringContainsString('"type":"ToolCallResult"', $output);
        $this->assertStringContainsString('"type":"TextMessageEnd"', $output);
        $this->assertStringContainsString('"type":"RunFinished"', $output);
    }

    private function createMockTool(string $name, array $inputs): Tool
    {
        return new class ($name, $inputs) extends Tool {
            public function __construct(string $name, array $inputs)
            {
                parent::__construct($name, 'Mock tool');
                $this->inputs = $inputs;
            }

            public function setResult(mixed $result): self
            {
                $this->result = $result;

                return $this;
            }

            public function description(): string
            {
                return 'Mock tool';
            }

            protected function handle(): string
            {
                return '';
            }

            protected function rules(): array
            {
                return [];
            }
        };
    }
}
