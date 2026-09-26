<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Splitter;

use InvalidArgumentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function mb_check_encoding;
use function preg_split;
use function range;
use function trim;

class SentenceTextSplitterTest extends TestCase
{
    /**
     * @return array<string, array{array<string, int>, string}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'zero max words' => [['maxWords' => 0], 'maxWords must be greater than 0'],
            'negative max words' => [['maxWords' => -1], 'maxWords must be greater than 0'],
            'negative overlap' => [['maxWords' => 10, 'overlapWords' => -1], 'overlapWords must be greater than or equal to 0'],
            'overlap equal to max words' => [['maxWords' => 10, 'overlapWords' => 10], 'Overlap must be less than maxWords'],
            'overlap greater than max words' => [['maxWords' => 10, 'overlapWords' => 50], 'Overlap must be less than maxWords'],
            'negative min words' => [['maxWords' => 10, 'minWords' => -1], 'minWords must be greater than or equal to 0'],
            'min words equal to max words' => [['maxWords' => 10, 'minWords' => 10], 'minWords must be less than maxWords'],
            'min words greater than max words' => [['maxWords' => 10, 'minWords' => 20], 'minWords must be less than maxWords'],
        ];
    }

    /**
     * @param array<string, int> $arguments
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new SentenceTextSplitter(...$arguments);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankTexts(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'newlines and tabs' => ["\n\n\t\n  \r\n"],
        ];
    }

    #[DataProvider('blankTexts')]
    public function test_blank_text_has_no_chunks(string $text): void
    {
        $this->assertSame([], (new SentenceTextSplitter(maxWords: 10))->splitDocument(new Document($text)));
    }

    public function test_short_sentences_are_grouped_into_one_chunk(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 10))->splitDocument(new Document('First sentence. Second sentence. Third sentence.'));

        $this->assertSame(['First sentence. Second sentence. Third sentence.'], $this->contents($result));
    }

    public function test_sentences_are_not_cut_when_they_fit_in_a_chunk(): void
    {
        $text = 'This is the first sentence. This is the second sentence. This is the third sentence.';

        $result = (new SentenceTextSplitter(maxWords: 10))->splitDocument(new Document($text));

        $this->assertSame(
            ['This is the first sentence. This is the second sentence.', 'This is the third sentence.'],
            $this->contents($result)
        );
    }

    public function test_overlap_prefixes_the_next_chunk_with_the_trailing_words_of_the_previous_one(): void
    {
        $text = 'One two three four five six seven eight nine ten. Eleven twelve thirteen fourteen fifteen.';

        $result = (new SentenceTextSplitter(maxWords: 10, overlapWords: 2))->splitDocument(new Document($text));

        $this->assertSame(
            ['One two three four five six seven eight nine ten.', 'nine ten. Eleven twelve thirteen fourteen fifteen.'],
            $this->contents($result)
        );
    }

    public function test_overlap_carries_across_long_sentences_and_shrinks_to_respect_max_words(): void
    {
        $text = 'This is a longer text that should be split into multiple chunks. ' .
                'This is the second sentence that should appear in two chunks. ' .
                'This is the third sentence that completes the text.';

        $result = (new SentenceTextSplitter(maxWords: 10, overlapWords: 2))->splitDocument(new Document($text));

        $this->assertSame([
            'This is a longer text that should be split into',
            'split into multiple chunks.',
            'multiple chunks. This is the second sentence that should appear',
            'should appear in two chunks.',
            'chunks. This is the third sentence that completes the text.',
        ], $this->contents($result));
    }

    public function test_overlap_preserves_all_words_in_long_sentences(): void
    {
        $words = array_map(static fn (int $index): string => "w{$index}", range(1, 300));
        $splitter = new SentenceTextSplitter(maxWords: 100, overlapWords: 20);

        $result = $splitter->splitDocument(new Document(implode(' ', $words)));

        $this->assertSame([
            implode(' ', array_slice($words, 0, 100)),
            implode(' ', array_slice($words, 80, 100)),
            implode(' ', array_slice($words, 160, 100)),
            implode(' ', array_slice($words, 240, 60)),
        ], $this->contents($result));
    }

    public function test_overlap_never_exceeds_max_words_when_tail_is_shorter_than_overlap(): void
    {
        $words = array_map(static fn (int $index): string => "w{$index}", range(1, 19));
        $splitter = new SentenceTextSplitter(maxWords: 10, overlapWords: 9);

        $result = $splitter->splitDocument(new Document(implode(' ', $words)));

        $this->assertCount(10, $result);
        foreach ($result as $index => $chunk) {
            $this->assertSame(array_slice($words, $index, 10), explode(' ', $chunk->getContent()));
        }
    }

    public function test_overlap_is_reduced_to_preserve_sentence_boundaries_and_max_words(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 5, overlapWords: 2);

        $result = $splitter->splitDocument(new Document('One two three four. Five six seven eight nine.'));

        $this->assertSame(['One two three four.', 'Five six seven eight nine.'], $this->contents($result));
    }

    public function test_long_sentence_is_split_on_word_boundaries(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 5))->splitDocument(new Document('one two three four five six seven eight nine ten'));

        $this->assertSame(['one two three four five', 'six seven eight nine ten'], $this->contents($result));
    }

    public function test_long_sentence_between_short_ones_gets_its_own_chunks(): void
    {
        $text = 'Short. This is a very long sentence that exceeds the chunk limit. End.';

        $result = (new SentenceTextSplitter(maxWords: 6))->splitDocument(new Document($text));

        $this->assertSame(
            ['Short.', 'This is a very long sentence', 'that exceeds the chunk limit.', 'End.'],
            $this->contents($result)
        );
    }

    public function test_paragraph_breaks_end_sentences(): void
    {
        $text = "First paragraph.\n\nSecond paragraph which is very long and contains many words and exceeds the chunk limit. Third paragraph.";

        $result = (new SentenceTextSplitter(maxWords: 10))->splitDocument(new Document($text));

        $this->assertSame([
            'First paragraph.',
            'Second paragraph which is very long and contains many words',
            'and exceeds the chunk limit.',
            'Third paragraph.',
        ], $this->contents($result));
        $this->assertSame(
            ['Intro here', 'Body text now.'],
            $this->contents((new SentenceTextSplitter(maxWords: 3))->splitDocument(new Document("Intro here\n\nBody text now.")))
        );
    }

    public function test_whitespace_inside_sentences_is_normalized_to_single_spaces(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 10))->splitDocument(new Document("  Line one\twith tab\nand   newline.  "));

        $this->assertSame(['Line one with tab and newline.'], $this->contents($result));
    }

    public function test_exclamation_question_and_ellipsis_end_sentences(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 3))->splitDocument(new Document('Stop now! Go there? Run fast… "Then" rest.'));

        $this->assertSame(['Stop now!', 'Go there?', 'Run fast…', '"Then" rest.'], $this->contents($result));
    }

    public function test_accented_capital_letters_start_a_new_sentence(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 4))->splitDocument(new Document('Première phrase ici. Éléphant arrive vite.'));

        $this->assertSame(['Première phrase ici.', 'Éléphant arrive vite.'], $this->contents($result));
    }

    public function test_a_period_followed_by_lowercase_does_not_end_the_sentence(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 3))->splitDocument(new Document('See fig. two now.'));

        $this->assertSame(['See fig. two', 'now.'], $this->contents($result));
    }

    public function test_multibyte_words_are_counted_once_and_never_cut(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 2))->splitDocument(new Document('naïve café résumé 日本語 🚀'));

        $this->assertSame(['naïve café', 'résumé 日本語', '🚀'], $this->contents($result));
        foreach ($result as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk->getContent(), 'UTF-8'));
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function textsWithoutOverlap(): array
    {
        $longText = file_get_contents(__DIR__.'/../Stub/long-text.txt');

        return [
            'fixture in large chunks' => [$longText, 200],
            'fixture in small chunks' => [$longText, 7],
            'single word chunks' => ['Alpha beta. Gamma delta epsilon! Zeta?', 1],
        ];
    }

    #[DataProvider('textsWithoutOverlap')]
    public function test_without_overlap_every_word_appears_once_in_order(string $text, int $maxWords): void
    {
        $result = (new SentenceTextSplitter(maxWords: $maxWords))->splitDocument(new Document($text));

        $chunkWords = array_map(static fn (Document $chunk): array => explode(' ', $chunk->getContent()), $result);
        foreach ($chunkWords as $words) {
            $this->assertLessThanOrEqual($maxWords, count($words));
        }
        $this->assertSame(preg_split('/\s+/u', trim($text)), array_merge(...$chunkWords));
    }

    public function test_min_words_merges_small_chunks(): void
    {
        $doc = new Document('One two three four five six. Seven eight. Nine ten eleven twelve thirteen.');

        $this->assertSame(
            ['One two three four five six.', 'Seven eight.', 'Nine ten eleven twelve thirteen.'],
            $this->contents((new SentenceTextSplitter(maxWords: 6))->splitDocument($doc))
        );
        $this->assertSame(
            ['One two three four five six. Seven eight.', 'Nine ten eleven twelve thirteen.'],
            $this->contents((new SentenceTextSplitter(maxWords: 6, minWords: 3))->splitDocument($doc))
        );
    }

    public function test_min_words_merges_only_chunks_shorter_than_the_minimum_into_the_previous_one(): void
    {
        $doc = new Document('Hi. One two three four five six. Seven eight nine.');

        $this->assertSame(
            ['Hi.', 'One two three four five six.', 'Seven eight nine.'],
            $this->contents((new SentenceTextSplitter(maxWords: 6, minWords: 3))->splitDocument($doc))
        );
    }

    public function test_source_and_metadata_are_copied_to_every_chunk(): void
    {
        $doc = new Document('First sentence here. Second sentence here. Third sentence here.');
        $doc->setSourceType('file');
        $doc->setSourceName('test.txt');
        $doc->addMetadata('key', 'value');

        $result = (new SentenceTextSplitter(maxWords: 5))->splitDocument($doc);

        $this->assertCount(3, $result);
        foreach ($result as $chunk) {
            $this->assertSame('file', $chunk->getSourceType());
            $this->assertSame('test.txt', $chunk->getSourceName());
            $this->assertSame(['key' => 'value'], $chunk->getMetadata());
        }
    }

    public function test_a_single_chunk_is_still_a_new_document_with_the_source(): void
    {
        $doc = (new Document('Test document.'))->setSourceType('test')->setSourceName('test.txt');

        $result = (new SentenceTextSplitter(maxWords: 10, overlapWords: 2))->splitDocument($doc);

        $this->assertSame(['Test document.'], $this->contents($result));
        $this->assertSame('test', $result[0]->getSourceType());
        $this->assertSame('test.txt', $result[0]->getSourceName());
    }

    public function test_every_chunk_has_its_own_identifier(): void
    {
        $result = (new SentenceTextSplitter(maxWords: 2))->splitDocument(new Document('One two. Three four. Five six.'));

        $ids = array_map(static fn (Document $chunk): string|int => $chunk->getId(), $result);
        $this->assertCount(3, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function test_split_documents_flattens_chunks_in_document_order(): void
    {
        $splitter = new SentenceTextSplitter(maxWords: 2);

        $result = $splitter->splitDocuments([new Document('One two. Three four.'), new Document(''), new Document('Five six.')]);

        $this->assertSame(['One two.', 'Three four.', 'Five six.'], $this->contents($result));
    }

    /**
     * @param Document[] $documents
     * @return string[]
     */
    protected function contents(array $documents): array
    {
        return array_map(static fn (Document $document): string => $document->getContent(), $documents);
    }
}
