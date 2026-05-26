<?php

declare(strict_types=1);

namespace Tests\RAG\Splitter;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function mb_strlen;

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

    public function test_min_length_merges_small_tail_chunk(): void
    {
        // "alpha beta" = 11 chars, "gamma" = 5 chars — tail "gamma" is below minLength=10
        $doc = new Document('alpha beta gamma');

        $splitter = new DelimiterTextSplitter(maxLength: 12, minLength: 10);
        $documents = $splitter->splitDocument($doc);

        // The small tail chunk is merged into the previous one
        $this->assertCount(1, $documents);
        $this->assertEquals('alpha beta gamma', $documents[0]->getContent());
    }

    public function test_min_length_zero_does_not_merge(): void
    {
        // Default minLength=0 means no merging — chunks are created as before
        $doc = new Document('alpha beta gamma');

        $splitter = new DelimiterTextSplitter(maxLength: 12);
        $documents = $splitter->splitDocument($doc);

        $this->assertCount(2, $documents);
    }

    public function test_min_length_invalid_when_ge_max(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(maxLength: 100, minLength: 100);
    }

    public function test_min_length_keeps_first_chunk_even_if_small(): void
    {
        // If there's only one chunk and it's below minLength, it's kept as-is
        $doc = new Document('short');

        $splitter = new DelimiterTextSplitter(maxLength: 1000, minLength: 50);
        $documents = $splitter->splitDocument($doc);

        $this->assertCount(1, $documents);
        $this->assertEquals('short', $documents[0]->getContent());
    }

    public function test_min_length_with_overlap(): void
    {
        // Ensure minLength merge works correctly when overlap is also set
        $doc = new Document(file_get_contents(__DIR__.'/../Stubs/long-text.txt'));

        $splitter = new DelimiterTextSplitter(maxLength: 500, wordOverlap: 2, minLength: 100);
        $documents = $splitter->splitDocument($doc);

        // No chunk (except possibly the first) should be below minLength
        foreach ($documents as $document) {
            $this->assertGreaterThanOrEqual(100, mb_strlen($document->getContent()));
        }
    }
}
