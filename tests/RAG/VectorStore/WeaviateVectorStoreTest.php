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
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;

use function count;

/**
 * Offline contract of the Weaviate REST and GraphQL calls; WeaviateTest covers a live server.
 */
class WeaviateVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected const HOST = 'http://weaviate.test:8080';

    protected function store(?string $key = null, ?DocumentSchema $schema = null, Response ...$responses): WeaviateVectorStore
    {
        return new WeaviateVectorStore(
            collection: 'articles',
            host: self::HOST . '/',
            key: $key,
            topK: 3,
            httpClient: $this->recordingClient($this->jsonResponse(['classes' => [['class' => 'ARTICLES']]]), ...$responses),
            schema: $schema,
        );
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::float('price')->filterable(),
            DocumentField::strings('tags')->filterable(),
            DocumentField::integer('year'),
        );
    }

    public function test_existing_class_is_matched_case_insensitively_and_not_recreated(): void
    {
        $this->store();

        $this->assertSame(['GET http://weaviate.test:8080/v1/schema'], $this->sentTargets());
    }

    public function test_missing_class_is_created_with_typed_schema_properties(): void
    {
        new WeaviateVectorStore(
            collection: 'articles',
            host: self::HOST,
            httpClient: $this->recordingClient($this->jsonResponse(['classes' => [['class' => 'Other']]]), new Response(200)),
            schema: DocumentSchema::of(
                DocumentField::string('tenant'),
                DocumentField::integer('year'),
                DocumentField::float('price'),
                DocumentField::boolean('draft'),
                DocumentField::strings('tags'),
                DocumentField::integers('years'),
                DocumentField::floats('prices'),
                DocumentField::booleans('flags'),
            ),
        );

        $this->assertSame('POST http://weaviate.test:8080/v1/schema', $this->sentTargets()[1]);
        $this->assertSame([
            'class' => 'Articles',
            'properties' => [
                ['name' => 'content', 'dataType' => ['text']],
                ['name' => 'sourceType', 'dataType' => ['text']],
                ['name' => 'sourceName', 'dataType' => ['text']],
                ['name' => 'metadata', 'dataType' => ['text']],
                ['name' => 'tenant', 'dataType' => ['text']],
                ['name' => 'year', 'dataType' => ['int']],
                ['name' => 'price', 'dataType' => ['number']],
                ['name' => 'draft', 'dataType' => ['boolean']],
                ['name' => 'tags', 'dataType' => ['text[]']],
                ['name' => 'years', 'dataType' => ['int[]']],
                ['name' => 'prices', 'dataType' => ['number[]']],
                ['name' => 'flags', 'dataType' => ['boolean[]']],
            ],
        ], $this->sentJson(1));
    }

    public function test_bearer_key_is_sent_only_when_configured(): void
    {
        $this->store('weaviate-key');
        $this->assertSame('Bearer weaviate-key', $this->sentRequest(0)->getHeaderLine('Authorization'));

        $this->sentRequests = [];
        $this->store('');
        $this->assertFalse($this->sentRequest(0)->hasHeader('Authorization'));
    }

    public function test_adds_objects_with_json_metadata_and_declared_properties(): void
    {
        $document = (new Document('Hello'))
            ->setId('6b1f3c2e-0000-4000-8000-000000000001')
            ->setEmbedding([1, 0])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'year' => 2026, 'extra' => ['x' => true]]);

        $this->store(null, $this->schema(), new Response(200))->addDocument($document);

        $this->assertSame('POST http://weaviate.test:8080/v1/batch/objects', $this->sentTargets()[1]);
        $this->assertSame(['objects' => [[
            'class' => 'Articles',
            'id' => '6b1f3c2e-0000-4000-8000-000000000001',
            'vector' => [1, 0],
            'properties' => [
                'content' => 'Hello',
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                'metadata' => '{"tenant":"acme","year":2026,"extra":{"x":true}}',
                'tenant' => 'acme',
                'year' => 2026,
            ],
        ]]], $this->sentJson(1));
    }

    public function test_unset_declared_fields_are_not_sent_as_null_properties(): void
    {
        $document = (new Document('Hello'))
            ->setId('obj-1')
            ->setEmbedding([1, 0])
            ->setMetadata(['tenant' => 'acme', 'price' => null]);

        $this->store(null, $this->schema(), new Response(200))->addDocument($document);

        $this->assertSame([
            'content' => 'Hello',
            'sourceType' => 'manual',
            'sourceName' => 'manual',
            'metadata' => '{"tenant":"acme","price":null}',
            'tenant' => 'acme',
        ], $this->sentJson(1)['objects'][0]['properties']);
    }

    public function test_search_renders_a_graphql_query_with_escaped_filter_values(): void
    {
        $store = $this->store(null, $this->schema(), $this->jsonResponse(['data' => ['Get' => ['Articles' => []]]]));

        $store->search(new SearchRequest(
            [0.5, -1, 2.25],
            FilterGroup::allOf(
                Filter::eq('tenant', 'acme") { __schema { types { name } } } #'),
                Filter::gt('price', 9.5),
            ),
            topK: 7,
        ));

        $this->assertSame('POST http://weaviate.test:8080/v1/graphql', $this->sentTargets()[1]);
        $query = $this->sentJson(1)['query'];
        $this->assertStringContainsString('Articles (', $query);
        $this->assertStringContainsString('nearVector: { vector: [0.5, -1, 2.25] }', $query);
        $this->assertStringContainsString('limit: 7', $query);
        $this->assertStringContainsString(
            'where: {operator: And, operands: [' .
            '{path: ["tenant"], operator: Equal, valueText: "acme\") { __schema { types { name } } } #"}, ' .
            '{path: ["price"], operator: GreaterThan, valueNumber: 9.5}]}',
            $query,
        );
    }

    public function test_search_without_filters_has_no_where_argument_and_uses_default_top_k(): void
    {
        $this->store(null, null, $this->jsonResponse(['data' => ['Get' => ['Articles' => []]]]))
            ->search(new SearchRequest([1.0]));

        $query = $this->sentJson(1)['query'];
        $this->assertStringNotContainsString('where:', $query);
        $this->assertStringContainsString('limit: 3', $query);
    }

    public function test_search_maps_objects_to_documents_with_similarity_scores(): void
    {
        $store = $this->store(null, null, $this->jsonResponse(['data' => ['Get' => ['Articles' => [
            [
                '_additional' => ['id' => 'obj-1', 'vector' => [0.1, 0.2], 'distance' => 0.2],
                'content' => 'First',
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                'metadata' => '{"tenant":"acme","year":2026}',
            ],
            [
                '_additional' => ['id' => 'obj-2', 'vector' => [], 'distance' => null],
                'content' => 'Second',
                'sourceType' => 'url',
                'sourceName' => 'b',
                'metadata' => 'not json',
            ],
        ]]]]));

        $results = $store->search(new SearchRequest([0.1, 0.2]));

        $this->assertCount(2, $results);
        $this->assertSame('obj-1', $results[0]->getId());
        $this->assertSame('First', $results[0]->getContent());
        $this->assertSame([0.1, 0.2], $results[0]->getEmbedding());
        $this->assertEqualsWithDelta(0.8, $results[0]->getScore(), 1e-12);
        $this->assertSame(['tenant' => 'acme', 'year' => 2026], $results[0]->getMetadata());

        $this->assertSame('obj-2', $results[1]->getId());
        $this->assertNull($results[1]->getEmbedding());
        $this->assertSame(1.0, $results[1]->getScore());
        $this->assertSame([], $results[1]->getMetadata());
    }

    public function test_stored_metadata_cannot_override_framework_fields(): void
    {
        $store = $this->store(null, null, $this->jsonResponse(['data' => ['Get' => ['Articles' => [
            [
                '_additional' => ['id' => 'obj-1', 'distance' => 0.5],
                'content' => 'Real',
                'sourceType' => 'file',
                'sourceName' => 'a.txt',
                'metadata' => '{"id":"forged","content":"forged","sourceType":"forged","sourceName":"forged","score":9,"embedding":[1],"tenant":"acme"}',
            ],
            [
                '_additional' => ['id' => 'obj-2', 'distance' => 0.5],
                'content' => 'Scalar metadata',
                'sourceType' => 'file',
                'sourceName' => 'b.txt',
                'metadata' => '"just text"',
            ],
        ]]]]));

        $results = $store->search(new SearchRequest([1.0]));

        $this->assertSame('obj-1', $results[0]->getId());
        $this->assertSame('Real', $results[0]->getContent());
        $this->assertSame('file', $results[0]->getSourceType());
        $this->assertSame('a.txt', $results[0]->getSourceName());
        $this->assertSame(0.5, $results[0]->getScore());
        $this->assertNull($results[0]->getEmbedding());
        $this->assertSame(['tenant' => 'acme'], $results[0]->getMetadata());
        $this->assertSame([], $results[1]->getMetadata());
    }

    public function test_search_on_an_unknown_class_returns_no_documents(): void
    {
        $results = $this->store(null, null, $this->jsonResponse(['errors' => [['message' => 'Cannot query field']]]))
            ->search(new SearchRequest([1.0]));

        $this->assertSame([], $results);
    }

    public function test_delete_sends_a_batch_delete_with_the_rest_filter(): void
    {
        $this->store(null, $this->schema(), new Response(200))->delete(FilterGroup::anyOf(
            Filter::eq('sourceName', 'a.txt'),
            Filter::containsAny('tags', ['old', 'stale']),
        ));

        $this->assertSame('DELETE http://weaviate.test:8080/v1/batch/objects', $this->sentTargets()[1]);
        $this->assertSame(['match' => [
            'class' => 'Articles',
            'where' => [
                'operator' => 'Or',
                'operands' => [
                    ['path' => ['sourceName'], 'operator' => 'Equal', 'valueText' => 'a.txt'],
                    ['path' => ['tags'], 'operator' => 'ContainsAny', 'valueTextArray' => ['old', 'stale']],
                ],
            ],
        ]], $this->sentJson(1));
    }

    public function test_filters_on_non_filterable_fields_are_rejected_before_any_query(): void
    {
        $store = $this->store(null, $this->schema());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document field "year" is not filterable.');

        try {
            $store->search(new SearchRequest([1.0], Filter::eq('year', 2026)));
        } finally {
            $this->assertCount(1, $this->sentRequests);
        }
    }

    public function test_destroy_deletes_the_class_schema(): void
    {
        $this->store(null, null, new Response(200))->destroy();

        $this->assertSame('DELETE http://weaviate.test:8080/v1/schema/Articles', $this->sentTargets()[1]);
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
