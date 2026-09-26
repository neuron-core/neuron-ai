<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use LogicException;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\InsertManyResult;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;

/**
 * Offline contract of the MongoDB writes; MongoDBTest covers Atlas search.
 * Search itself needs ext-mongodb cursors and is only covered there.
 */
class MongoDBVectorStoreWriteTest extends TestCase
{
    use RejectsInvalidInputBeforeRemoteCalls;

    protected Collection&MockObject $collection;

    protected function store(?DocumentSchema $schema = null): MongoDBVectorStore
    {
        $this->collection = $this->createMock(Collection::class);

        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('selectCollection')
            ->with('rag', 'chunks')
            ->willReturn($this->collection);

        return new MongoDBVectorStore($client, 'rag', 'chunks', 4, 'vectors', $schema);
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year'),
        );
    }

    public function test_inserts_documents_with_string_ids_and_nested_metadata(): void
    {
        $store = $this->store($this->schema());
        $document = (new Document('Hello'))
            ->setId(12)
            ->setEmbedding([1, 0])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'year' => 2026]);

        $this->collection->expects($this->once())
            ->method('insertMany')
            ->with([[
                '_id' => '12',
                'embedding' => [1.0, 0.0],
                'content' => 'Hello',
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                'metadata' => (object) ['tenant' => 'acme', 'year' => 2026],
            ]]);

        $store->addDocument($document);
    }

    public function test_inserts_in_batches_of_one_hundred(): void
    {
        $store = $this->store();
        $documents = array_map(
            static fn (int $i): Document => (new Document("doc {$i}"))->setEmbedding([1, 0]),
            range(1, 250),
        );

        $batchSizes = [];
        $this->collection->expects($this->exactly(3))
            ->method('insertMany')
            ->willReturnCallback(function (array $batch) use (&$batchSizes): InsertManyResult {
                $batchSizes[] = count($batch);
                return $this->createStub(InsertManyResult::class);
            });

        $store->addDocuments($documents);

        $this->assertSame([100, 100, 50], $batchSizes);
    }

    public function test_adding_no_documents_writes_nothing(): void
    {
        $store = $this->store();
        $this->collection->expects($this->never())->method('insertMany');

        $store->addDocuments([]);
    }

    public function test_invalid_documents_are_rejected_before_any_write(): void
    {
        $store = $this->store($this->schema());
        $this->collection->expects($this->never())->method('insertMany');

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('is missing required metadata field "tenant".');

        $store->addDocuments([(new Document('No tenant'))->setEmbedding([1, 0])]);
    }

    public function test_delete_many_receives_the_compiled_filter_with_nested_metadata_paths(): void
    {
        $store = $this->store($this->schema());

        $this->collection->expects($this->once())
            ->method('deleteMany')
            ->with(['$and' => [
                ['sourceType' => ['$eq' => 'file']],
                ['metadata.tenant' => ['$ne' => 'acme', '$exists' => true]],
            ]]);

        $store->delete(FilterGroup::allOf(Filter::eq('sourceType', 'file'), Filter::neq('tenant', 'acme')));
    }

    public function test_delete_with_a_non_filterable_field_never_reaches_the_collection(): void
    {
        $store = $this->store($this->schema());
        $this->collection->expects($this->never())->method('deleteMany');

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document field "year" is not filterable.');

        $store->delete(Filter::eq('year', 2026));
    }

    /**
     * @return array<string, array{SearchRequest, array<string, mixed>}>
     */
    public static function vectorSearches(): array
    {
        return [
            'default top k with the minimum candidate pool' => [
                new SearchRequest([0.5, 1.0]),
                ['index' => 'vectors', 'path' => 'embedding', 'queryVector' => [0.5, 1.0], 'numCandidates' => 100, 'limit' => 4],
            ],
            'request top k with a filter on nested metadata' => [
                new SearchRequest([0.5, 1.0], Filter::eq('tenant', 'acme'), topK: 12),
                [
                    'index' => 'vectors',
                    'path' => 'embedding',
                    'queryVector' => [0.5, 1.0],
                    'numCandidates' => 120,
                    'limit' => 12,
                    'filter' => ['metadata.tenant' => ['$eq' => 'acme']],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $expectedStage
     */
    #[DataProvider('vectorSearches')]
    public function test_search_sends_a_vector_search_stage(SearchRequest $request, array $expectedStage): void
    {
        $store = $this->store($this->schema());
        $sent = [];
        // Real aggregation cursors need ext-mongodb, so the fake stops the search once the pipeline is captured.
        $this->collection->expects($this->once())
            ->method('aggregate')
            ->willReturnCallback(static function (array $pipeline, array $options) use (&$sent): never {
                $sent = [$pipeline, $options];
                throw new LogicException('Cursor not available offline.');
            });

        $this->expectException(LogicException::class);

        try {
            $store->search($request);
        } finally {
            [$pipeline, $options] = $sent;
            $this->assertSame(['$vectorSearch' => $expectedStage], $pipeline[0]);
            $this->assertSame(['typeMap' => ['root' => 'array', 'document' => 'array']], $options);
        }
    }

    public function test_vector_index_declares_the_embedding_and_every_filterable_field(): void
    {
        $store = $this->store($this->schema());

        $this->collection->expects($this->once())
            ->method('createSearchIndex')
            ->with(
                ['fields' => [
                    ['type' => 'vector', 'path' => 'embedding', 'numDimensions' => 3, 'similarity' => 'dotProduct'],
                    ['type' => 'filter', 'path' => 'metadata.tenant'],
                    ['type' => 'filter', 'path' => 'sourceType'],
                    ['type' => 'filter', 'path' => 'sourceName'],
                ]],
                ['name' => 'vectors', 'type' => 'vectorSearch'],
            )
            ->willReturn('vectors');

        $store->setupVectorIndex(3, 'dotProduct');
    }

    public function test_drop_collection_drops_the_selected_collection(): void
    {
        $store = $this->store();
        $this->collection->expects($this->once())->method('drop');

        $store->dropCollection();
    }

    protected function storeRejectingInvalidInput(): VectorStoreInterface
    {
        $store = $this->store();
        foreach (['insertMany', 'deleteMany', 'aggregate'] as $method) {
            $this->collection->expects($this->never())->method($method);
        }

        return $store;
    }

    protected function remoteCallCount(): int
    {
        return 0;
    }
}
