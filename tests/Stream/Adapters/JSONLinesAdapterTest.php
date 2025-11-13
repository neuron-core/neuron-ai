<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Stream\Adapters;

use NeuronAI\Chat\Messages\Stream\ReasoningChunk;
use NeuronAI\Chat\Messages\Stream\TextChunk;
use NeuronAI\Chat\Messages\Stream\ToolCallChunk;
use NeuronAI\Chat\Messages\Stream\ToolResultChunk;
use NeuronAI\Stream\Adapters\JSONLinesAdapter;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

class JSONLinesAdapterTest extends TestCase
{
    private JSONLinesAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new JSONLinesAdapter();
    }

    public function test_get_headers_returns_correct_headers(): void
    {
        $headers = $this->adapter->getHeaders();

        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertEquals('application/x-ndjson', $headers['Content-Type']);
        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertEquals('no-cache', $headers['Cache-Control']);
    }

    public function test_start_returns_empty_array(): void
    {
        $result = \iterator_to_array($this->adapter->start());

        $this->assertEmpty($result);
    }

    public function test_end_returns_done_message(): void
    {
        $result = \iterator_to_array($this->adapter->end());

        $this->assertCount(1, $result);
        $this->assertStringContainsString('"type":"done"', $result[0]);
        $this->assertStringEndsWith("\n", $result[0]);
    }

    public function test_transform_text_chunk(): void
    {
        $chunk = new TextChunk('Hello world');
        $result = \iterator_to_array($this->adapter->transform($chunk));

        $this->assertCount(1, $result);
        $this->assertStringEndsWith("\n", $result[0]);

        $decoded = \json_decode(\trim($result[0]), true);
        $this->assertEquals('text', $decoded['type']);
        $this->assertEquals('Hello world', $decoded['content']);
    }

    public function test_transform_reasoning_chunk(): void
    {
        $chunk = new ReasoningChunk('Analyzing the problem...');
        $result = \iterator_to_array($this->adapter->transform($chunk));

        $this->assertCount(1, $result);

        $decoded = \json_decode(\trim($result[0]), true);
        $this->assertEquals('reasoning', $decoded['type']);
        $this->assertEquals('Analyzing the problem...', $decoded['content']);
    }

    public function test_transform_tool_call_chunk(): void
    {
        $tool = $this->createMockTool('calculator', ['a' => 5, 'b' => 10]);
        $chunk = new ToolCallChunk([$tool]);

        $result = \iterator_to_array($this->adapter->transform($chunk));

        $this->assertCount(1, $result);

        $decoded = \json_decode(\trim($result[0]), true);
        $this->assertEquals('tool_call', $decoded['type']);
        $this->assertIsArray($decoded['tools']);
        $this->assertCount(1, $decoded['tools']);
        $this->assertEquals('calculator', $decoded['tools'][0]['name']);
        $this->assertEquals(['a' => 5, 'b' => 10], $decoded['tools'][0]['inputs']);
    }

    public function test_transform_tool_result_chunk(): void
    {
        $tool = $this->createMockTool('calculator', ['a' => 5, 'b' => 10]);
        $tool->setResult('15');

        $chunk = new ToolResultChunk([$tool]);

        $result = \iterator_to_array($this->adapter->transform($chunk));

        $this->assertCount(1, $result);

        $decoded = \json_decode(\trim($result[0]), true);
        $this->assertEquals('tool_result', $decoded['type']);
        $this->assertIsArray($decoded['tools']);
        $this->assertCount(1, $decoded['tools']);
        $this->assertEquals('calculator', $decoded['tools'][0]['name']);
        $this->assertEquals('15', $decoded['tools'][0]['result']);
    }

    public function test_transform_multiple_tools(): void
    {
        $tool1 = $this->createMockTool('calculator', ['op' => 'add']);
        $tool2 = $this->createMockTool('weather', ['city' => 'NYC']);

        $chunk = new ToolCallChunk([$tool1, $tool2]);

        $result = \iterator_to_array($this->adapter->transform($chunk));

        $this->assertCount(1, $result);

        $decoded = \json_decode(\trim($result[0]), true);
        $this->assertEquals('tool_call', $decoded['type']);
        $this->assertCount(2, $decoded['tools']);
        $this->assertEquals('calculator', $decoded['tools'][0]['name']);
        $this->assertEquals('weather', $decoded['tools'][1]['name']);
    }

    public function test_output_is_valid_json_lines(): void
    {
        $chunks = [
            new TextChunk('Hello'),
            new ReasoningChunk('Thinking'),
            new TextChunk('World'),
        ];

        $allOutput = '';
        foreach ($chunks as $chunk) {
            $result = \iterator_to_array($this->adapter->transform($chunk));
            $allOutput .= $result[0];
        }

        $lines = \explode("\n", \trim($allOutput));
        $this->assertCount(3, $lines);

        foreach ($lines as $line) {
            $decoded = \json_decode($line, true);
            $this->assertNotNull($decoded, "Line should be valid JSON: $line");
            $this->assertArrayHasKey('type', $decoded);
        }
    }

    public function test_unknown_chunk_type(): void
    {
        $unknownChunk = new class () {
        };
        $result = \iterator_to_array($this->adapter->transform($unknownChunk));

        $this->assertCount(1, $result);

        $decoded = \json_decode(\trim($result[0]), true);
        $this->assertEquals('unknown', $decoded['type']);
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
