<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;

/**
 * Offline contract of the Qdrant REST calls; QdrantTest covers a live server.
 */
class QdrantVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected const COLLECTION_URL = 'http://qdrant.test:6333/collections/docs/';

    protected function store(?string $key = null, ?DocumentSchema $schema = null, Response ...$responses): QdrantVectorStore
    {
        return new QdrantVectorStore(
            collectionUrl: self::COLLECTION_URL,
            key: $key,
            topK: 2,
            dimension: 3,
            httpClient: $this->recordingClient($this->exists(true), ...$responses),
            schema: $schema,
        );
    }

    protected function exists(bool $exists): Response
    {
        return $this->jsonResponse(['result' => ['exists' => $exists]]);
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::strings('tags')->filterable(),
        );
    }

    protected function document(string $id): Document
    {
        return (new Document("content {$id}"))
            ->setId($id)
            ->setEmbedding([1, 0, 0])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'tags' => ['php']]);
    }

    public function test_existing_collection_is_not_recreated(): void
    {
        $this->store();

        $this->assertSame(['GET http://qdrant.test:6333/collections/docs/exists'], $this->sentTargets());
    }

    public function test_missing_collection_is_created_with_cosine_distance_and_dimension(): void
    {
        new QdrantVectorStore(
            collectionUrl: self::COLLECTION_URL,
            dimension: 768,
            httpClient: $this->recordingClient($this->exists(false), new Response(200)),
        );

        $this->assertSame([
            'GET http://qdrant.test:6333/collections/docs/exists',
            'PUT http://qdrant.test:6333/collections/docs',
        ], $this->sentTargets());
        $this->assertSame(['vectors' => ['size' => 768, 'distance' => 'Cosine']], $this->sentJson(1));
    }

    public function test_api_key_header_is_sent_only_when_configured(): void
    {
        $this->store('qdrant-secret');
        $this->assertSame('qdrant-secret', $this->sentRequest(0)->getHeaderLine('api-key'));

        $this->sentRequests = [];
        $this->store('');
        $this->assertFalse($this->sentRequest(0)->hasHeader('api-key'));
    }

    public function test_adds_points_with_payload_and_projected_filter_fields(): void
    {
        $this->store(null, $this->schema(), new Response(200))->addDocument($this->document('11111111-1111-1111-1111-111111111111'));

        $this->assertSame('PUT http://qdrant.test:6333/collections/docs/points?wait=true', $this->sentTargets()[1]);
        $this->assertSame(['points' => [[
            'id' => '11111111-1111-1111-1111-111111111111',
            'payload' => [
                'content' => 'content 11111111-1111-1111-1111-111111111111',
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                '_neuron_metadata' => '{"tenant":"acme","tags":["php"]}',
                'tenant' => 'acme',
                'tags' => ['php'],
            ],
            'vector' => [1, 0, 0],
        ]]], $this->sentJson(1));
    }

    public function test_adds_points_in_chunks_of_one_hundred(): void
    {
        $documents = array_map(fn (int $i): Document => $this->document("doc-{$i}"), range(1, 150));

        $this->store(null, $this->schema(), new Response(200), new Response(200))->addDocuments($documents);

        $this->assertCount(3, $this->sentRequests);
        $this->assertSame([100, 50], [count($this->sentJson(1)['points']), count($this->sentJson(2)['points'])]);
    }

    public function test_invalid_document_is_rejected_before_any_point_is_written(): void
    {
        $store = $this->store(null, $this->schema());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('metadata field "tags" expects string[]; array given.');

        try {
            $store->addDocuments([$this->document('a'), $this->document('b')->addMetadata('tags', [1, 2])]);
        } finally {
            $this->assertCount(1, $this->sentRequests);
        }
    }

    public function test_search_sends_the_query_with_filters_and_maps_points(): void
    {
        $store = $this->store(null, $this->schema(), $this->jsonResponse(['result' => ['points' => [[
            'id' => 'point-1',
            'score' => 0.91,
            'vector' => [0.5, 0.5, 0],
            'payload' => [
                'content' => 'Qdrant content',
                'sourceType' => 'url',
                'sourceName' => 'https://example.test',
                '_neuron_metadata' => '{"tenant":"acme","tags":["php","rag"]}',
                'tenant' => 'acme',
            ],
        ]]]]));

        $results = $store->search(new SearchRequest(
            [0.5, 0.5, 0],
            FilterGroup::anyOf(Filter::eq('tenant', 'acme'), Filter::containsAny('tags', ['rag'])),
        ));

        $this->assertSame('POST http://qdrant.test:6333/collections/docs/points/query', $this->sentTargets()[1]);
        $this->assertSame([
            'query' => ['recommend' => ['positive' => [[0.5, 0.5, 0]]]],
            'limit' => 2,
            'with_payload' => true,
            'with_vector' => true,
            'filter' => ['must' => [['should' => [
                ['key' => 'tenant', 'match' => ['value' => 'acme']],
                ['key' => 'tags', 'match' => ['any' => ['rag']]],
            ]]]],
        ], $this->sentJson(1));

        $this->assertCount(1, $results);
        $this->assertSame('point-1', $results[0]->getId());
        $this->assertSame('Qdrant content', $results[0]->getContent());
        $this->assertSame([0.5, 0.5, 0.0], $results[0]->getEmbedding());
        $this->assertSame('url', $results[0]->getSourceType());
        $this->assertSame('https://example.test', $results[0]->getSourceName());
        $this->assertSame(0.91, $results[0]->getScore());
        $this->assertSame(['tenant' => 'acme', 'tags' => ['php', 'rag']], $results[0]->getMetadata());
    }

    public function test_search_without_filters_sends_no_filter_and_honors_request_top_k(): void
    {
        $this->store(null, null, $this->jsonResponse(['result' => ['points' => []]]))
            ->search(new SearchRequest([1, 0, 0], topK: 25));

        $body = $this->sentJson(1);
        $this->assertArrayNotHasKey('filter', $body);
        $this->assertSame(25, $body['limit']);
    }

    public function test_delete_posts_the_compiled_filter(): void
    {
        $this->store(null, null, new Response(200))->delete(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            Filter::neq('sourceName', 'keep.txt'),
        ));

        $this->assertSame('POST http://qdrant.test:6333/collections/docs/points/delete?wait=true', $this->sentTargets()[1]);
        $this->assertSame(['filter' => ['must' => [
            ['key' => 'sourceType', 'match' => ['value' => 'file']],
            ['key' => 'sourceName', 'match' => ['except' => ['keep.txt']]],
        ]]], $this->sentJson(1));
    }

    public function test_filter_on_a_non_filterable_field_is_rejected_before_deleting(): void
    {
        $store = $this->store(null, DocumentSchema::of(DocumentField::string('tenant')));

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document field "tenant" is not filterable.');

        try {
            $store->delete(Filter::eq('tenant', 'acme'));
        } finally {
            $this->assertCount(1, $this->sentRequests);
        }
    }

    public function test_destroy_deletes_the_collection(): void
    {
        $this->store(null, null, new Response(200))->destroy();

        $this->assertSame('DELETE http://qdrant.test:6333/collections/docs', $this->sentTargets()[1]);
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
