<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\Exceptions\DataReaderException;
use NeuronAI\RAG\DataLoader\HtmlReader;
use NeuronAI\RAG\DataLoader\ReaderInterface;
use NeuronAI\RAG\DataLoader\TextFileReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function uniqid;

class ReaderFailureTest extends TestCase
{
    /**
     * @return array<string, array{class-string<ReaderInterface>}>
     */
    public static function readers(): array
    {
        return [
            'text' => [TextFileReader::class],
            'html' => [HtmlReader::class],
        ];
    }

    /**
     * @param class-string<ReaderInterface> $reader
     */
    #[DataProvider('readers')]
    public function test_missing_file_raises_a_data_reader_exception_naming_the_path(string $reader): void
    {
        $path = sys_get_temp_dir() . '/neuron-missing-' . uniqid() . '.txt';

        $this->expectException(DataReaderException::class);
        $this->expectExceptionMessage($path);

        @$reader::getText($path);
    }
}
