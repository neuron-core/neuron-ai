<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Testing\VectorStoreRecord;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function range;
use function sort;

class RAGIngestionTest extends TestCase
{
    protected FakeEmbeddingsProvider $embeddings;

    protected FakeVectorStore $store;

    protected function setUp(): void
    {
        $this->embeddings = new FakeEmbeddingsProvider();
        $this->store = new FakeVectorStore();
    }

    protected function rag(): RAG
    {
        return RAG::make()->setEmbeddingsProvider($this->embeddings)->setVectorStore($this->store);
    }

    protected function document(string $content, string $sourceType, string $sourceName): Document
    {
        return (new Document($content))->setSourceType($sourceType)->setSourceName($sourceName);
    }

    /**
     * @return array<int, string[]>
     */
    protected function storedBatches(): array
    {
        $batches = [];
        foreach ($this->store->getRecorded() as $record) {
            if ($record->method === 'addDocuments') {
                $batches[] = array_map(static fn (Document $document): string => $document->getContent(), $record->documents);
            }
        }

        return $batches;
    }

    /** @return string[] */
    protected function storedContents(): array
    {
        $contents = array_map(static fn (Document $document): string => $document->getContent(), $this->store->getDocuments());
        sort($contents);

        return $contents;
    }

    public function test_documents_are_embedded_and_stored_in_chunks_of_the_given_size(): void
    {
        $documents = array_map(static fn (int $index): Document => new Document("Document {$index}"), range(1, 5));

        $this->rag()->addDocuments($documents, chunkSize: 2);

        $this->assertSame([
            ['Document 1', 'Document 2'],
            ['Document 3', 'Document 4'],
            ['Document 5'],
        ], $this->storedBatches());
        $this->embeddings->assertCallCount(5);
        $reference = new FakeEmbeddingsProvider();
        foreach ($documents as $document) {
            $this->assertSame($reference->embedText($document->getContent()), $document->getEmbedding());
        }
    }

    public function test_a_chunk_size_of_one_stores_each_document_separately(): void
    {
        $this->rag()->addDocuments([new Document('First'), new Document('Second')], chunkSize: 1);

        $this->assertSame([['First'], ['Second']], $this->storedBatches());
    }

    public function test_documents_fitting_one_chunk_are_stored_at_once(): void
    {
        $this->rag()->addDocuments([new Document('First'), new Document('Second')], chunkSize: 2);

        $this->assertSame([['First', 'Second']], $this->storedBatches());
    }

    public function test_no_documents_touch_neither_the_embeddings_provider_nor_the_store(): void
    {
        $this->rag()->addDocuments([]);

        $this->embeddings->assertNothingEmbedded();
        $this->assertSame([], $this->store->getRecorded());
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    public function test_a_chunk_size_below_one_is_rejected_before_any_work(int $chunkSize): void
    {
        try {
            $this->rag()->addDocuments([new Document('Document')], $chunkSize);
            $this->fail('A chunk size below one must be rejected.');
        } catch (AgentException $exception) {
            $this->assertSame('RAG document chunk size must be greater than zero.', $exception->getMessage());
        }

        $this->embeddings->assertNothingEmbedded();
        $this->store->assertNothingStored();
    }

    public function test_reindexing_replaces_only_the_documents_of_the_given_sources(): void
    {
        $rag = $this->rag();
        $rag->addDocuments([
            $this->document('Old A1', 'file', 'a.md'),
            $this->document('Old A2', 'file', 'a.md'),
            $this->document('Old B', 'file', 'b.md'),
            $this->document('Same name, other type', 'url', 'a.md'),
        ]);

        $rag->reindexBySource([
            $this->document('New A', 'file', 'a.md'),
            $this->document('New C', 'file', 'c.md'),
        ]);

        $this->assertSame(['New A', 'New C', 'Old B', 'Same name, other type'], $this->storedContents());
    }

    public function test_reindexing_deletes_each_source_by_its_exact_type_and_name(): void
    {
        $this->rag()->reindexBySource([
            $this->document('First', 'file', 'a.md'),
            $this->document('Second', 'file', 'a.md'),
            $this->document('Third', 'url', 'a.md'),
        ]);

        $deletes = array_map(
            static fn (VectorStoreRecord $record): array => $record->filters?->toArray() ?? [],
            array_values(array_filter($this->store->getRecorded(), static fn (VectorStoreRecord $record): bool => $record->method === 'delete')),
        );
        $this->assertSame([
            FilterGroup::and(Filter::eq('sourceType', 'file'), Filter::eq('sourceName', 'a.md'))->toArray(),
            FilterGroup::and(Filter::eq('sourceType', 'url'), Filter::eq('sourceName', 'a.md'))->toArray(),
        ], $deletes);
        $this->assertSame([['First', 'Second'], ['Third']], $this->storedBatches());
    }

    public function test_reindexing_a_source_name_that_looks_like_a_filter_does_not_widen_the_delete(): void
    {
        $rag = $this->rag();
        $rag->addDocuments([
            $this->document('Victim', 'file', 'victim.md'),
            $this->document('Old', 'file', "x' OR '1'='1"),
        ]);

        $rag->reindexBySource([$this->document('New', 'file', "x' OR '1'='1")]);

        $this->assertSame(['New', 'Victim'], $this->storedContents());
    }

    public function test_reindexing_stores_each_source_in_chunks_of_the_given_size(): void
    {
        $this->rag()->reindexBySource([
            $this->document('First', 'file', 'a.md'),
            $this->document('Second', 'file', 'a.md'),
            $this->document('Third', 'file', 'a.md'),
        ], chunkSize: 2);

        $this->assertSame([['First', 'Second'], ['Third']], $this->storedBatches());
    }
}
