<?php

declare(strict_types=1);

namespace Tests\RAG\Splitter;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use PHPUnit\Framework\TestCase;

use function file_get_contents;

class DelimiterTextSplitterTest extends TestCase
{
    public function test_split_long_text(): void
    {
        $doc = new Document(file_get_contents(__DIR__.'/../Stubs/long-text.txt'));

        $splitter = new DelimiterTextSplitter();
        $documents = $splitter->splitDocument($doc);
        $this->assertCount(7, $documents);

        $splitter = new DelimiterTextSplitter(maxLength: 500);
        $documents = $splitter->splitDocument($doc);
        $this->assertCount(14, $documents);

        $splitter = new DelimiterTextSplitter(maxLength: 1000, separator: "\n");
        $documents = $splitter->splitDocument($doc);
        $this->assertCount(12, $documents);
    }
}
