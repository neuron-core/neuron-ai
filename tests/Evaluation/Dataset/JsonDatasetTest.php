<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Evaluation\Dataset;

use InvalidArgumentException;
use NeuronAI\Evaluation\Dataset\JsonDataset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class JsonDatasetTest extends TestCase
{
    protected string $file;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'neuron-dataset-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function test_loads_the_items_in_file_order(): void
    {
        file_put_contents($this->file, '[{"question":"Capital of Italy?","expected":"Rome"},{"question":"2+2?","expected":4,"tags":["math"]}]');

        $this->assertSame([
            ['question' => 'Capital of Italy?', 'expected' => 'Rome'],
            ['question' => '2+2?', 'expected' => 4, 'tags' => ['math']],
        ], (new JsonDataset($this->file))->load());
    }

    public function test_preserves_unicode_content(): void
    {
        file_put_contents($this->file, '[{"text":"Caffè ☕ 日本"}]');

        $this->assertSame([['text' => 'Caffè ☕ 日本']], (new JsonDataset($this->file))->load());
    }

    public function test_empty_array_is_an_empty_dataset(): void
    {
        file_put_contents($this->file, '[]');

        $this->assertSame([], (new JsonDataset($this->file))->load());
    }

    public function test_missing_file_is_rejected_at_construction(): void
    {
        unlink($this->file);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Dataset file not found: {$this->file}");

        new JsonDataset($this->file);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedJson(): iterable
    {
        yield 'empty file' => ['', 'Invalid JSON in dataset file: Syntax error'];
        yield 'truncated' => ['[{"question": "a"', 'Invalid JSON in dataset file: Syntax error'];
        yield 'trailing comma' => ['[{"question": "a"},]', 'Invalid JSON in dataset file: Syntax error'];
        yield 'invalid utf-8' => ["[{\"question\": \"\xC3\x28\"}]", 'Invalid JSON in dataset file: Malformed UTF-8 characters, possibly incorrectly encoded'];
    }

    #[DataProvider('malformedJson')]
    public function test_malformed_json_is_rejected_with_the_parser_error(string $content, string $message): void
    {
        file_put_contents($this->file, $content);
        $dataset = new JsonDataset($this->file);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $dataset->load();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonArrayJson(): iterable
    {
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'string' => ['"items"'];
        yield 'boolean' => ['true'];
    }

    #[DataProvider('nonArrayJson')]
    public function test_non_array_json_is_rejected(string $content): void
    {
        file_put_contents($this->file, $content);
        $dataset = new JsonDataset($this->file);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dataset must be an array of objects');

        $dataset->load();
    }

    public function test_reads_the_file_at_load_time(): void
    {
        file_put_contents($this->file, '[{"version":1}]');
        $dataset = new JsonDataset($this->file);

        file_put_contents($this->file, '[{"version":2}]');

        $this->assertSame([['version' => 2]], $dataset->load());
    }
}
