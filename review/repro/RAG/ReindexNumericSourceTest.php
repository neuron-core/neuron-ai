<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;

class ReindexNumericSourceTest extends TestCase
{
    public static function numericSources(): array
    {
        return [
            'numeric source name' => ['post', '2024'],
            'numeric source type' => ['42', 'file.txt'],
        ];
    }

    #[DataProvider('numericSources')]
    public function test_a_source_identified_by_digits_can_be_reindexed(string $sourceType, string $sourceName): void
    {
        $store = new FakeVectorStore();
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($store);
        $rag->addDocuments([(new Document('Old'))->setSourceType($sourceType)->setSourceName($sourceName)]);

        $rag->reindexBySource([(new Document('New'))->setSourceType($sourceType)->setSourceName($sourceName)]);

        $this->assertSame(['New'], array_map(static fn (Document $document): string => $document->getContent(), $store->getDocuments()));
    }
}
