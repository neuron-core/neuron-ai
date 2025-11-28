<?php

declare(strict_types=1);

namespace NeuronAI\Tests\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use PHPUnit\Framework\TestCase;

use const PHP_EOL;

class DataLoaderTest extends TestCase
{
    public function test_string_data_loader(): void
    {
        $documents = StringDataLoader::for('test')->getDocuments();
        $this->assertCount(1, $documents);
        $this->assertEquals('test', $documents[0]->getContent());
    }

    public function test_file_data_loader(): void
    {
        $documents = FileDataLoader::for(__DIR__.'/target.txt')
            ->withSplitter(
                new DelimiterTextSplitter(
                    10,
                    PHP_EOL
                )
            )
            ->getDocuments();

        $this->assertCount(12, $documents);
    }
}
