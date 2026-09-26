<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function str_repeat;

use const PHP_EOL;

class DataLoaderTest extends TestCase
{
    public function test_string_data_loader_returns_short_text_as_a_single_manual_document(): void
    {
        $documents = StringDataLoader::for('test')->getDocuments();

        $this->assertCount(1, $documents);
        $this->assertSame('test', $documents[0]->getContent());
        $this->assertSame('manual', $documents[0]->getSourceType());
        $this->assertSame('manual', $documents[0]->getSourceName());
    }

    public function test_string_data_loader_returns_no_documents_for_empty_text(): void
    {
        $this->assertSame([], StringDataLoader::for('')->getDocuments());
    }

    public function test_default_splitter_keeps_up_to_one_thousand_characters_together(): void
    {
        $text = str_repeat('a', 499) . '.' . str_repeat('b', 500);

        $this->assertSame([$text], $this->contents(StringDataLoader::for($text)->getDocuments()));
    }

    public function test_default_splitter_cuts_longer_text_on_periods(): void
    {
        $text = str_repeat('a', 500) . '.' . str_repeat('b', 500) . '.';

        $this->assertSame(
            [str_repeat('a', 500), str_repeat('b', 500)],
            $this->contents(StringDataLoader::for($text)->getDocuments())
        );
    }

    public function test_custom_splitter_replaces_the_default_one(): void
    {
        $documents = StringDataLoader::for('One two. Three four.')
            ->withSplitter(new SentenceTextSplitter(maxWords: 2))
            ->getDocuments();

        $this->assertSame(['One two.', 'Three four.'], $this->contents($documents));
    }

    public function test_file_data_loader_splits_a_single_file_with_the_configured_splitter(): void
    {
        $path = __DIR__.'/target.txt';

        $documents = FileDataLoader::for($path)
            ->withSplitter(new DelimiterTextSplitter(maxLength: 10, separator: PHP_EOL))
            ->getDocuments();

        $lines = array_values(array_filter(explode(PHP_EOL, file_get_contents($path)), static fn (string $line): bool => $line !== ''));
        $this->assertCount(12, $lines);
        $this->assertSame($lines, $this->contents($documents));
        foreach ($documents as $document) {
            $this->assertSame('files', $document->getSourceType());
            $this->assertSame($path, $document->getSourceName());
        }
        $this->assertCount(count($documents), array_unique(array_map(static fn (Document $document): string|int => $document->getId(), $documents)));
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
