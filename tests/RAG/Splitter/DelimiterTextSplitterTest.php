<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Splitter;

use InvalidArgumentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function mb_check_encoding;
use function mb_strlen;
use function str_repeat;

class DelimiterTextSplitterTest extends TestCase
{
    /**
     * @return array<string, array{array<string, int|string>, string}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'zero max length' => [['maxLength' => 0], 'maxLength must be greater than 0'],
            'negative max length' => [['maxLength' => -1], 'maxLength must be greater than 0'],
            'empty separator' => [['separator' => ''], 'separator must not be empty'],
            'negative word overlap' => [['wordOverlap' => -1], 'wordOverlap must be greater than or equal to 0'],
            'negative min length' => [['minLength' => -1], 'minLength must be greater than or equal to 0'],
            'min length equal to max length' => [['maxLength' => 100, 'minLength' => 100], 'minLength must be less than maxLength'],
            'min length greater than max length' => [['maxLength' => 100, 'minLength' => 200], 'minLength must be less than maxLength'],
        ];
    }

    /**
     * @param array<string, int|string> $arguments
     */
    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected(array $arguments, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new DelimiterTextSplitter(...$arguments);
    }

    public function test_split_long_text(): void
    {
        $doc = new Document(file_get_contents(__DIR__.'/../Stub/long-text.txt'));

        $this->assertCount(7, (new DelimiterTextSplitter())->splitDocument($doc));
        $this->assertCount(14, (new DelimiterTextSplitter(maxLength: 500))->splitDocument($doc));
        $this->assertCount(12, (new DelimiterTextSplitter(maxLength: 1000, separator: "\n"))->splitDocument($doc));
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function textsWithoutOverlap(): array
    {
        $longText = file_get_contents(__DIR__.'/../Stub/long-text.txt');

        return [
            'fixture on spaces' => [$longText, 500, ' '],
            'fixture on newlines' => [$longText, 1000, "\n"],
            'fixture on periods' => [$longText, 300, '.'],
            'multi character separator' => ['alpha--beta--gamma--delta--epsilon--zeta', 12, '--'],
        ];
    }

    #[DataProvider('textsWithoutOverlap')]
    public function test_without_overlap_chunks_rebuild_the_text_without_losing_or_repeating_parts(string $text, int $maxLength, string $separator): void
    {
        $chunks = $this->contents((new DelimiterTextSplitter(maxLength: $maxLength, separator: $separator))->splitDocument(new Document($text)));

        $parts = array_values(array_filter(explode($separator, $text), static fn (string $part): bool => $part !== ''));
        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame(implode($separator, $parts), implode($separator, $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual($maxLength, mb_strlen($chunk));
        }
    }

    public function test_empty_text_has_no_chunks(): void
    {
        $this->assertSame([], (new DelimiterTextSplitter(maxLength: 10))->splitDocument(new Document('')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textsWithinMaxLength(): array
    {
        return [
            'shorter than max length' => ['a  b '],
            'exactly max length' => ['abcd  efgh'],
            'multibyte characters count once each' => ['àèìò  ÀÈÌ🚀'],
        ];
    }

    #[DataProvider('textsWithinMaxLength')]
    public function test_text_within_max_length_is_kept_verbatim(string $text): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10))->splitDocument(new Document($text));

        $this->assertSame([$text], $this->contents($result));
    }

    public function test_separators_only_text_longer_than_max_length_has_no_chunks(): void
    {
        $this->assertSame([], (new DelimiterTextSplitter(maxLength: 10))->splitDocument(new Document(str_repeat(' ', 25))));
    }

    public function test_text_one_character_over_max_length_is_split(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10))->splitDocument(new Document('abcd efghij'));

        $this->assertSame(['abcd', 'efghij'], $this->contents($result));
    }

    public function test_chunk_length_is_measured_in_characters_and_never_cuts_a_multibyte_character(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 7))->splitDocument(new Document('ééé ààà 日本語 🚀🚀'));

        $this->assertSame(['ééé ààà', '日本語 🚀🚀'], $this->contents($result));
        foreach ($result as $chunk) {
            $this->assertTrue(mb_check_encoding($chunk->getContent(), 'UTF-8'));
        }
    }

    public function test_repeated_and_edge_separators_do_not_produce_empty_words(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10))->splitDocument(new Document('  alpha   beta  gamma  '));

        $this->assertSame(['alpha beta', 'gamma'], $this->contents($result));
    }

    public function test_word_longer_than_max_length_becomes_its_own_uncut_chunk(): void
    {
        $longWord = str_repeat('x', 25);

        $result = (new DelimiterTextSplitter(maxLength: 5))->splitDocument(new Document("{$longWord} a {$longWord}"));

        $this->assertSame([$longWord, 'a', $longWord], $this->contents($result));
    }

    public function test_multi_char_separator_counts_towards_max_length(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 20, separator: '--'))
            ->splitDocument(new Document('alpha--beta--gamma--delta--epsilon--zeta'));

        $this->assertSame(['alpha--beta--gamma', 'delta--epsilon--zeta'], $this->contents($result));
    }

    public function test_overlap_repeats_trailing_words_of_the_previous_chunk(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 13, wordOverlap: 1))
            ->splitDocument(new Document('one two three four five six'));

        $this->assertSame(['one two three', 'three four', 'four five six'], $this->contents($result));
    }

    public function test_overlap_with_a_multi_char_separator_counts_the_separator_towards_max_length(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10, separator: '--', wordOverlap: 1))
            ->splitDocument(new Document('aa--bb--cc--dd--eee'));

        $this->assertSame(['aa--bb--cc', 'cc--dd', 'dd--eee'], $this->contents($result));
    }

    public function test_overlap_that_does_not_fit_drops_the_oldest_words_first(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10, wordOverlap: 2))
            ->splitDocument(new Document('aa bb cc dddddd'));

        $this->assertSame(['aa bb cc', 'cc dddddd'], $this->contents($result));
    }

    public function test_overlap_larger_than_a_chunk_still_moves_forward(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 3, wordOverlap: 100))
            ->splitDocument(new Document('a b c d e f'));

        $this->assertSame(['a b', 'b c', 'c d', 'd e', 'e f'], $this->contents($result));
    }

    public function test_overlap_is_reduced_to_preserve_content_and_max_length(): void
    {
        $splitter = new DelimiterTextSplitter(maxLength: 6, wordOverlap: 1);

        $result = $splitter->splitDocument(new Document('aa bb cccc dd'));

        $this->assertSame(['aa bb', 'cccc', 'dd'], $this->contents($result));
    }

    public function test_min_length_merges_small_chunks(): void
    {
        $doc = new Document('This is a test of the splitter functionality with some text');

        $this->assertSame(
            ['This is a test of', 'the splitter', 'functionality with', 'some text'],
            $this->contents((new DelimiterTextSplitter(maxLength: 20))->splitDocument($doc))
        );
        $this->assertSame(
            ['This is a test of the splitter', 'functionality with some text'],
            $this->contents((new DelimiterTextSplitter(maxLength: 20, minLength: 15))->splitDocument($doc))
        );
    }

    public function test_min_length_merges_only_chunks_shorter_than_the_minimum_into_the_previous_one(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10, minLength: 5))
            ->splitDocument(new Document('ab cdefghij klmnopqrs tu abcdefghi vwxyz'));

        $this->assertSame(['ab', 'cdefghij', 'klmnopqrs tu', 'abcdefghi', 'vwxyz'], $this->contents($result));
    }

    public function test_min_length_merges_small_chunks_back_with_the_configured_separator(): void
    {
        $result = (new DelimiterTextSplitter(maxLength: 10, separator: '.', minLength: 3))
            ->splitDocument(new Document('aaaaaaaa.bbbbbbbbb.c'));

        $this->assertSame(['aaaaaaaa', 'bbbbbbbbb.c'], $this->contents($result));
    }

    public function test_source_and_metadata_are_copied_to_every_chunk(): void
    {
        $doc = new Document('Test content that is long enough to not be returned as-is when splitting with a small max length');
        $doc->setSourceType('file');
        $doc->setSourceName('test.txt');
        $doc->addMetadata('key', 'value');
        $doc->addMetadata('tags', ['a', 'b']);

        $result = (new DelimiterTextSplitter(maxLength: 30))->splitDocument($doc);

        $this->assertCount(4, $result);
        foreach ($result as $chunk) {
            $this->assertSame('file', $chunk->getSourceType());
            $this->assertSame('test.txt', $chunk->getSourceName());
            $this->assertSame(['key' => 'value', 'tags' => ['a', 'b']], $chunk->getMetadata());
        }
    }

    public function test_every_chunk_has_its_own_identifier(): void
    {
        $doc = new Document('one two three four five six seven eight');

        $result = (new DelimiterTextSplitter(maxLength: 9))->splitDocument($doc);

        $ids = array_map(static fn (Document $chunk): string|int => $chunk->getId(), $result);
        $this->assertCount(5, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertNotContains($doc->getId(), $ids);
    }

    public function test_split_documents_flattens_chunks_in_document_order(): void
    {
        $splitter = new DelimiterTextSplitter(maxLength: 5);
        $first = (new Document('aa bb cc'))->setSourceName('first');
        $second = (new Document('dd ee'))->setSourceName('second');

        $result = $splitter->splitDocuments([$first, $second]);

        $this->assertSame(['aa bb', 'cc', 'dd ee'], $this->contents($result));
        $this->assertSame(
            ['first', 'first', 'second'],
            array_map(static fn (Document $chunk): string => $chunk->getSourceName(), $result)
        );
        $this->assertSame([], $splitter->splitDocuments([]));
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
