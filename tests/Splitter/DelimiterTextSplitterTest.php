<?php

declare(strict_types=1);

namespace Tests\RAG\Splitter;

use InvalidArgumentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
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

    public function test_min_length_merges_small_chunks(): void
    {
        $text = "This is a test of the splitter functionality with some text";
        $doc = new Document($text);

        // Without minLength: 4 chunks
        $splitter = new DelimiterTextSplitter(maxLength: 20);
        $result = $splitter->splitDocument($doc);
        $this->assertCount(4, $result);

        // With minLength: short chunks merged into previous
        $splitter = new DelimiterTextSplitter(maxLength: 20, minLength: 15);
        $result = $splitter->splitDocument($doc);
        $this->assertCount(2, $result);
        $this->assertEquals('This is a test of the splitter', $result[0]->getContent());
        $this->assertEquals('functionality with some text', $result[1]->getContent());
    }

    public function test_min_length_equal_to_max_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(maxLength: 100, minLength: 100);
    }

    public function test_min_length_greater_than_max_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(maxLength: 100, minLength: 200);
    }

    public function test_zero_max_length_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(maxLength: 0);
    }

    public function test_negative_max_length_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(maxLength: -1);
    }

    public function test_empty_separator_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(separator: '');
    }

    public function test_negative_word_overlap_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(wordOverlap: -1);
    }

    public function test_negative_min_length_throws_exception(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DelimiterTextSplitter(minLength: -1);
    }

    public function test_metadata_is_propagated(): void
    {
        $doc = new Document('Test content that is long enough to not be returned as-is when splitting with a small max length');
        $doc->sourceType = 'file';
        $doc->sourceName = 'test.txt';
        $doc->addMetadata('key', 'value');

        $splitter = new DelimiterTextSplitter(maxLength: 30);
        $result = $splitter->splitDocument($doc);

        foreach ($result as $chunk) {
            $this->assertEquals('file', $chunk->getSourceType());
            $this->assertEquals('test.txt', $chunk->getSourceName());
            $this->assertEquals(['key' => 'value'], $chunk->metadata);
        }
    }

    public function test_multi_char_separator_chunk_lengths(): void
    {
        $text = "alpha--beta--gamma--delta--epsilon--zeta";
        $doc = new Document($text);

        // With '--' separator, each word is separated by 2 chars
        // maxLength=20 should produce predictable chunks
        $splitter = new DelimiterTextSplitter(maxLength: 20, separator: '--');
        $result = $splitter->splitDocument($doc);

        foreach ($result as $chunk) {
            $this->assertLessThanOrEqual(20, mb_strlen($chunk->getContent()));
        }
    }
}
