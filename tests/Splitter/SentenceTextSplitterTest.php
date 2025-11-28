<?php

declare(strict_types=1);

namespace Tests\RAG\Splitter;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

use function array_map;
use function array_slice;
use function count;
use function explode;
use function implode;
use function preg_split;
use function str_contains;
use function substr_count;
use function trim;

class SentenceTextSplitterTest extends TestCase
{
    public function test_split_document_with_overlap(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 2);

        $text = "This is a longer text that should be split into multiple chunks. " .
                "This is the second sentence that should appear in two chunks. " .
                "This is the third sentence that completes the text.";

        $document = new Document($text);
        $document->sourceType = 'test';
        $document->sourceName = 'test.txt';

        $result = $splitter->splitDocument($document);

        $this->assertGreaterThan(1, count($result));

        // Verify that the overlap is present
        $firstChunkWords = explode(' ', $result[0]->getContent());
        $secondChunkWords = explode(' ', $result[1]->getContent());

        $this->assertEquals(
            array_slice($firstChunkWords, -2),
            array_slice($secondChunkWords, 0, 2)
        );
    }

    public function test_split_document_preserves_metadata(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 2);

        $text = "Test document.";
        $document = new Document($text);
        $document->sourceType = 'test';
        $document->sourceName = 'test.txt';

        $result = $splitter->splitDocument($document);

        $this->assertCount(1, $result);
        $this->assertEquals('test', $result[0]->getSourceType());
        $this->assertEquals('test.txt', $result[0]->getSourceName());
    }

    public function test_invalid_overlap_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SentenceTextSplitter(maxWords: 10, overlapWords: 10);
    }

    public function test_split_document_without_overlap(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 0);

        $text = "This is the first sentence. This is the second sentence. This is the third sentence.";
        $document = new Document($text);
        $document->sourceType = 'test';
        $document->sourceName = 'test.txt';

        $result = $splitter->splitDocument($document);

        // Verify there are exactly 2 chunks
        $this->assertCount(2, $result);

        // Verify no chunk exceeds the word limit
        foreach ($result as $chunk) {
            $words = preg_split('/\s+/u', trim($chunk->getContent()));
            $this->assertLessThanOrEqual(10, count($words), 'Chunk exceeds word limit');
        }

        // Verify all sentences are present exactly once
        $sentences = [
            'This is the first sentence.',
            'This is the second sentence.',
            'This is the third sentence.'
        ];

        $allContent = implode(' ', array_map(fn (Document $c): string => $c->getContent(), $result));

        foreach ($sentences as $sentence) {
            $this->assertStringContainsString($sentence, $allContent, "The sentence '$sentence' is not present");
            // Verify the sentence appears exactly once
            $this->assertEquals(1, substr_count($allContent, $sentence), "The sentence '$sentence' appears more than once");
        }

        // Verify there is no overlap between chunks
        $firstChunkWords = preg_split('/\s+/u', trim($result[0]->getContent()));
        $secondChunkWords = preg_split('/\s+/u', trim($result[1]->getContent()));

        // Last words of first chunk should not be the first words of second chunk
        $lastWordsOfFirst = array_slice($firstChunkWords, -2);
        $firstWordsOfSecond = array_slice($secondChunkWords, 0, 2);
        $this->assertNotEquals($lastWordsOfFirst, $firstWordsOfSecond, 'Overlap present when it should not be');
    }

    public function test_chunking_base(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 0);

        $text = "First sentence. Second sentence. Third sentence.";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('First sentence. Second sentence. Third sentence.', $result[0]->getContent());
    }

    public function test_chunking_with_overlap(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 2);

        $text = "One two three four five six seven eight nine ten. Eleven twelve thirteen fourteen fifteen.";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        $this->assertGreaterThan(1, count($result));

        $firstChunkWords = preg_split('/\s+/u', trim($result[0]->getContent()));
        $secondChunkWords = preg_split('/\s+/u', trim($result[1]->getContent()));

        $this->assertEquals(
            array_slice($firstChunkWords, -2),
            array_slice($secondChunkWords, 0, 2)
        );
    }

    public function test_long_sentence_is_split(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 5, overlapWords: 0);

        $text = "one two three four five six seven eight nine ten";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        $this->assertGreaterThan(1, count($result));

        foreach ($result as $chunk) {
            $words = preg_split('/\s+/u', trim($chunk->getContent()));
            $this->assertLessThanOrEqual(5, count($words));
        }
    }

    public function test_paragraphs_not_split(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 0);

        $text = "First paragraph.\n\nSecond paragraph which is very long and contains many words and exceeds the chunk limit. Third paragraph.";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        $this->assertStringContainsString('First paragraph.', $result[0]->getContent());

        $found = false;
        foreach ($result as $chunk) {
            if (str_contains($chunk->getContent(), 'Second paragraph')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'The second paragraph must be present in at least one chunk.');

        $found = false;
        foreach ($result as $chunk) {
            if (str_contains($chunk->getContent(), 'Third paragraph.')) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'The third paragraph must be present in at least one chunk.');
    }

    public function test_chunking_with_short_and_long_sentences(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 6, overlapWords: 0);

        $text = "Short. This is a very long sentence that exceeds the chunk limit. End.";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        foreach ($result as $chunk) {
            $words = preg_split('/\s+/u', trim($chunk->getContent()));
            $this->assertLessThanOrEqual(6, count($words));
        }

        $allContent = implode(' ', array_map(fn (Document $c): string => $c->getContent(), $result));

        $this->assertStringContainsString('Short.', $allContent);
        $this->assertStringContainsString('End.', $allContent);
    }

    public function test_empty_text(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 0);

        $text = "   ";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        $this->assertCount(0, $result);
    }

    public function test_single_sentence(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 0);

        $text = "Only one sentence.";
        $document = new Document($text);

        $result = $splitter->splitDocument($document);

        $this->assertCount(1, $result);
        $this->assertEquals('Only one sentence.', trim($result[0]->getContent()));
    }
}
