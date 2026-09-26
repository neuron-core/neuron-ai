<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;

/**
 * Offline contract of the Chroma v2 REST calls; ChromaDBTest covers a live server.
 */
class ChromaVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected const COLLECTIONS = 'http://chroma.test:8000/api/v2/tenants/acme/databases/prod/collections';

    protected function store(?DocumentSchema $schema = null, Response ...$responses): ChromaVectorStore
    {
        return new ChromaVectorStore(
            collection: 'docs',
            host: 'http://chroma.test:8000/',
            tenant: 'acme',
            database: 'prod',
            topK: 4,
            httpClient: $this->recordingClient($this->jsonResponse(['id' => 'col-uuid']), ...$responses),
            schema: $schema,
        );
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::strings('tags')->filterable(),
            DocumentField::integer('year'),
        );
    }

    public function test_gets_or_creates_the_collection_in_the_configured_tenant_and_database(): void
    {
        $this->store();

        $this->assertSame(['POST ' . self::COLLECTIONS], $this->sentTargets());
        $this->assertSame(['name' => 'docs', 'get_or_create' => true], $this->sentJson(0));
    }

    public function test_adds_documents_as_parallel_arrays_to_the_resolved_collection_id(): void
    {
        $document = (new Document('Hello'))
            ->setId(7)
            ->setEmbedding([1, 2])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tags' => ['php'], 'year' => 2026]);

        $this->store($this->schema(), new Response(200))->addDocument($document);

        $this->assertSame('POST ' . self::COLLECTIONS . '/col-uuid/add', $this->sentTargets()[1]);
        $this->assertSame([
            'ids' => ['7'],
            'documents' => ['Hello'],
            'embeddings' => [[1, 2]],
            'metadatas' => [[
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                '_neuron_metadata' => '{"tags":["php"],"year":2026}',
                'tags' => ['php'],
            ]],
        ], $this->sentJson(1));
    }

    public function test_adds_documents_in_chunks_of_one_hundred(): void
    {
        $documents = array_map(
            static fn (int $i): Document => (new Document("doc {$i}"))->setEmbedding([1, 0]),
            range(1, 101),
        );

        $this->store(null, new Response(200), new Response(200))->addDocuments($documents);

        $this->assertSame([
            'POST ' . self::COLLECTIONS,
            'POST ' . self::COLLECTIONS . '/col-uuid/add',
            'POST ' . self::COLLECTIONS . '/col-uuid/add',
        ], $this->sentTargets());
        $this->assertCount(100, $this->sentJson(1)['ids']);
        $this->assertSame(['doc 101'], $this->sentJson(2)['documents']);
    }

    public function test_search_maps_distances_to_similarity_scores_and_hydrates_metadata(): void
    {
        $store = $this->store($this->schema(), $this->jsonResponse([
            'ids' => [['a', 'b']],
            'documents' => [['First', 'Second']],
            'metadatas' => [[
                ['sourceType' => 'file', 'sourceName' => 'a.txt', '_neuron_metadata' => '{"tags":["php"]}'],
                null,
            ]],
            'distances' => [[0.25, 0.0]],
        ]));

        $results = $store->search(new SearchRequest([1, 2], Filter::containsAll('tags', ['php', 'rag']), topK: 2));

        $this->assertSame('POST ' . self::COLLECTIONS . '/col-uuid/query', $this->sentTargets()[1]);
        $this->assertSame([
            'query_embeddings' => [[1, 2]],
            'n_results' => 2,
            'include' => ['documents', 'metadatas', 'distances'],
            'where' => ['$and' => [
                ['tags' => ['$contains' => 'php']],
                ['tags' => ['$contains' => 'rag']],
            ]],
        ], $this->sentJson(1));

        $this->assertCount(2, $results);
        $this->assertSame('a', $results[0]->getId());
        $this->assertSame('First', $results[0]->getContent());
        $this->assertSame('file', $results[0]->getSourceType());
        $this->assertSame('a.txt', $results[0]->getSourceName());
        $this->assertSame(0.75, $results[0]->getScore());
        $this->assertSame(['tags' => ['php']], $results[0]->getMetadata());
        $this->assertSame('b', $results[1]->getId());
        $this->assertSame(1.0, $results[1]->getScore());
        $this->assertSame('manual', $results[1]->getSourceType());
        $this->assertSame('manual', $results[1]->getSourceName());
        $this->assertSame([], $results[1]->getMetadata());
    }

    public function test_search_without_results_returns_an_empty_list(): void
    {
        $results = $this->store(null, $this->jsonResponse(['ids' => [[]]]))->search(new SearchRequest([1, 2]));

        $this->assertSame([], $results);
        $this->assertArrayNotHasKey('where', $this->sentJson(1));
        $this->assertSame(4, $this->sentJson(1)['n_results']);
    }

    public function test_delete_posts_the_compiled_where_clause(): void
    {
        $this->store(null, new Response(200))->delete(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            Filter::in('sourceName', ['a.txt', 'b.txt']),
        ));

        $this->assertSame('POST ' . self::COLLECTIONS . '/col-uuid/delete', $this->sentTargets()[1]);
        $this->assertSame(['where' => ['$and' => [
            ['sourceType' => ['$eq' => 'file']],
            ['sourceName' => ['$in' => ['a.txt', 'b.txt']]],
        ]]], $this->sentJson(1));
    }

    public function test_delete_refuses_a_pinecone_raw_filter_even_though_the_dialects_look_alike(): void
    {
        $store = $this->store();

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . PineconeVectorStore::class);

        try {
            $store->delete(Filter::raw(PineconeVectorStore::class, ['sourceType' => ['$eq' => 'file']]));
        } finally {
            $this->assertCount(1, $this->sentRequests);
        }
    }

    public function test_destroy_deletes_the_collection_by_name(): void
    {
        $this->store(null, new Response(200))->destroy();

        $this->assertSame('DELETE ' . self::COLLECTIONS . '/docs', $this->sentTargets()[1]);
    }

    protected function storeRejectingInvalidInput(): VectorStoreInterface
    {
        return $this->store();
    }

    protected function remoteCallCount(): int
    {
        return count($this->sentRequests);
    }
}
