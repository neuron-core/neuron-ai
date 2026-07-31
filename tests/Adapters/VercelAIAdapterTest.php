<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Adapters;

use NeuronAI\Chat\Messages\Stream\Chunks\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolArgumentChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\Chunks\ToolResultChunk;
use NeuronAI\Chat\Messages\Stream\Adapters\VercelAIAdapter;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function json_decode;
use function preg_match;
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

    public function test_transform_text_chunk(): void
    {
        // Create fresh adapter to ensure clean state
        $adapter = new VercelAIAdapter();
        $chunk = new TextChunk('msg_123', 'Hello world');

        // Manually collect results to preserve order
        $result = [];
        foreach ($adapter->transform($chunk) as $item) {
            $result[] = $item;
        }

        // Should have 2 messages: start + text-delta
        $this->assertCount(2, $result);

        // First output should be message start
        $this->assertStringContainsString('"type":"start"', $result[0]);
        $this->assertStringContainsString('"messageId":"msg_', $result[0]);

        // Second output should be text delta
        $this->assertStringContainsString('"type":"text-delta"', $result[1]);
        $this->assertStringContainsString('"delta":"Hello world"', $result[1]);
    }

    public function test_transform_reasoning_chunk(): void
    {
        // Initialize with a text chunk first (consume the generator so it executes)
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);

        $chunk = new ReasoningChunk('sig_123', 'Thinking...');
        $result = iterator_to_array($this->adapter->transform($chunk), false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"reasoning-delta"', $result[0]);
        $this->assertStringContainsString('"delta":"Thinking..."', $result[0]);
    }

    public function test_transform_tool_call_chunk(): void
    {
        // Initialize with a text chunk first (consume the generator so it executes)
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);

        $tool = $this->createMockTool('calculator', ['operation' => 'add']);
        $chunk = new ToolCallChunk($tool);

        $result = iterator_to_array($this->adapter->transform($chunk), false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"tool-input-available"', $result[0]);
        $this->assertStringContainsString('"toolName":"calculator"', $result[0]);
        $this->assertStringContainsString('"operation":"add"', $result[0]);
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

        // First fragment opens the input stream
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
        iterator_to_array($this->adapter->transform(new TextChunk('msg_123', 'init')), false);
        iterator_to_array($this->adapter->transform(new ToolArgumentChunk('msg_123', 'calculator', '{"operation":"add"}', 'call_1')), false);

        $tool = $this->createMockTool('calculator', ['operation' => 'add'])->setCallId('call_1');
        $result = iterator_to_array($this->adapter->transform(new ToolCallChunk($tool)), false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"tool-input-available"', $result[0]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $result[0]);

        $tool->setResult('42');
        $result = iterator_to_array($this->adapter->transform(new ToolResultChunk($tool)), false);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"tool-output-available"', $result[0]);
        $this->assertStringContainsString('"toolCallId":"call_1"', $result[0]);
    }

    public function test_message_id_is_consistent_across_chunks(): void
    {
        // Use fresh adapter to test state persistence
        $adapter = new VercelAIAdapter();

        $chunk1 = new TextChunk('msg_123', 'Hello');
        $result1 = [];
        foreach ($adapter->transform($chunk1) as $item) {
            $result1[] = $item;
        }

        $chunk2 = new TextChunk('msg_123', ' world');
        $result2 = [];
        foreach ($adapter->transform($chunk2) as $item) {
            $result2[] = $item;
        }

        // Extract message ID from first result (start message)
        preg_match('/"messageId":"([^"]+)"/', $result1[0], $matches1);
        $messageId1 = $matches1[1] ?? null;

        // Extract message ID from second result (text-delta uses "id" field)
        preg_match('/"messageId":"([^"]+)"/', $result2[0], $matches2);
        $messageId2 = $matches2[1] ?? null;

        $this->assertNotNull($messageId1);
        $this->assertNotNull($messageId2);
        $this->assertEquals($messageId1, $messageId2);
    }

    public function test_sse_format_is_correct(): void
    {
        $chunk = new TextChunk('msg_123', 'Test');
        $result = iterator_to_array($this->adapter->transform($chunk), false);

        // Both the lazy "start" event and the text-delta must survive collection
        $this->assertCount(2, $result);

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

    private function createMockTool(string $name, array $inputs): Tool
    {
        return new class ($name, $inputs) extends Tool {
            protected ?string $description = 'Mock tool';

            public function __construct(protected string $name, array $inputs)
            {
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
