<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use Exception;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

use function count;
use function array_map;
use function array_slice;
use function explode;
use function json_decode;
use function range;

use const JSON_THROW_ON_ERROR;

/**
 * Offline contract of the Typesense requests; TypesenseTest covers a live server.
 */
class TypesenseVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected function store(?DocumentSchema $schema, Response ...$responses): TypesenseVectorStore
    {
        $client = new Client([
            'api_key' => 'typesense-key',
            'nodes' => [['host' => 'ts.test', 'port' => '8108', 'protocol' => 'http']],
            'client' => $this->recordingPsrClient(...$responses),
            'num_retries' => 0,
            'log_level' => 600,
        ]);

        return new TypesenseVectorStore($client, 'docs', 2, '3', $schema);
    }

    protected function collection(int $dimension = 2): Response
    {
        return $this->jsonResponse(['name' => 'docs', 'fields' => [
            ['name' => 'content', 'type' => 'string'],
            ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => $dimension],
        ]]);
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year'),
            DocumentField::floats('scores'),
            DocumentField::boolean('draft')->filterable(),
        );
    }

    protected function document(string $id = 'doc-1'): Document
    {
        return (new Document('Hello'))
            ->setId($id)
            ->setEmbedding([0.5, 0.25])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'year' => 2026]);
    }

    public function test_missing_collection_is_created_with_typed_schema_fields(): void
    {
        $store = $this->store($this->schema(), $this->jsonResponse(['message' => 'Not Found'], 404), $this->jsonResponse(), $this->jsonResponse());

        $store->addDocument($this->document());

        $this->assertSame([
            'GET http://ts.test:8108/collections/docs',
            'POST http://ts.test:8108/collections',
            'POST http://ts.test:8108/collections/docs/documents/',
        ], $this->sentTargets());
        $this->assertSame([
            'name' => 'docs',
            'fields' => [
                ['name' => 'content', 'type' => 'string'],
                ['name' => 'sourceType', 'type' => 'string', 'facet' => true],
                ['name' => 'sourceName', 'type' => 'string', 'facet' => true],
                ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 2],
                ['name' => '_neuron_metadata', 'type' => 'string', 'optional' => true, 'index' => false],
                ['name' => 'tenant', 'type' => 'string', 'optional' => false, 'facet' => true],
                ['name' => 'year', 'type' => 'int64', 'optional' => true, 'facet' => false],
                ['name' => 'scores', 'type' => 'float[]', 'optional' => true, 'facet' => false],
                ['name' => 'draft', 'type' => 'bool', 'optional' => true, 'facet' => true],
            ],
        ], $this->sentJson(1));
        $this->assertSame([
            'id' => 'doc-1',
            'content' => 'Hello',
            'embedding' => [0.5, 0.25],
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            '_neuron_metadata' => '{"tenant":"acme","year":2026}',
            'tenant' => 'acme',
        ], $this->sentJson(2));
        $this->assertSame('typesense-key', $this->sentRequest(0)->getHeaderLine('X-TYPESENSE-API-KEY'));
    }

    public function test_every_schema_field_type_maps_to_its_typesense_type(): void
    {
        $schema = DocumentSchema::of(
            DocumentField::string('tenant'),
            DocumentField::strings('tags'),
            DocumentField::integer('year'),
            DocumentField::integers('versions'),
            DocumentField::float('rating'),
            DocumentField::floats('scores'),
            DocumentField::boolean('draft'),
            DocumentField::booleans('flags'),
        );
        $store = $this->store($schema, $this->jsonResponse(['message' => 'Not Found'], 404), $this->jsonResponse(), $this->jsonResponse());

        $store->addDocument($this->document());

        $types = [];
        foreach (array_slice($this->sentJson(1)['fields'], 5) as $field) {
            $types[$field['name']] = $field['type'];
        }
        $this->assertSame([
            'tenant' => 'string',
            'tags' => 'string[]',
            'year' => 'int64',
            'versions' => 'int64[]',
            'rating' => 'float',
            'scores' => 'float[]',
            'draft' => 'bool',
            'flags' => 'bool[]',
        ], $types);
    }

    public function test_embedding_dimension_mismatch_with_an_existing_collection_is_refused(): void
    {
        $store = $this->store(null, $this->collection(3), $this->collection(3));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/^Vector embeddings dimension 2 must be the same as the initial setup /');

        try {
            $store->addDocument($this->document());
        } finally {
            $this->assertSame([
                'GET http://ts.test:8108/collections/docs',
                'GET http://ts.test:8108/collections/docs',
            ], $this->sentTargets());
        }
    }

    public function test_bulk_import_sends_one_json_line_per_document(): void
    {
        $store = $this->store(null, $this->collection(), $this->collection(), new Response(200, [], "{\"success\":true}\n{\"success\":true}"));

        $store->addDocuments([$this->document('a'), $this->document('b')->setId(7)]);

        $this->assertSame('POST http://ts.test:8108/collections/docs/documents/import', $this->sentTargets()[2]);
        $lines = explode("\n", (string) $this->sentRequest(2)->getBody());
        $this->assertCount(2, $lines);
        $this->assertSame('a', json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR)['id']);
        $this->assertSame('7', json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['id']);
    }

    public function test_bulk_import_is_split_into_chunks_of_one_hundred_lines(): void
    {
        $documents = array_map(fn (int $i): Document => $this->document("doc-{$i}"), range(1, 101));
        $store = $this->store(null, $this->collection(), $this->collection(), new Response(200), new Response(200));

        $store->addDocuments($documents);

        $this->assertSame([
            'GET http://ts.test:8108/collections/docs',
            'GET http://ts.test:8108/collections/docs',
            'POST http://ts.test:8108/collections/docs/documents/import',
            'POST http://ts.test:8108/collections/docs/documents/import',
        ], $this->sentTargets());
        $this->assertCount(100, explode("\n", (string) $this->sentRequest(2)->getBody()));
        $this->assertSame('doc-101', json_decode((string) $this->sentRequest(3)->getBody(), true, flags: JSON_THROW_ON_ERROR)['id']);
    }

    public function test_adding_no_documents_sends_nothing(): void
    {
        $this->store(null)->addDocuments([]);

        $this->assertSame([], $this->sentRequests);
    }

    public function test_search_sends_a_filtered_vector_query_and_maps_hits(): void
    {
        $store = $this->store($this->schema(), $this->jsonResponse(['results' => [['hits' => [[
            'document' => [
                'id' => 'doc-5',
                'content' => 'Found',
                'sourceType' => 'url',
                'sourceName' => 'b',
                '_neuron_metadata' => '{"tenant":"acme","year":2020}',
                'tenant' => 'acme',
            ],
            'vector_distance' => 0.2,
        ]]]]]));

        $results = $store->search(new SearchRequest(
            [0.5, 0.25],
            FilterGroup::allOf(Filter::eq('tenant', 'a && b'), Filter::eq('draft', false)),
            topK: 20,
        ));

        $this->assertSame('POST http://ts.test:8108/multi_search', $this->sentTargets()[0]);
        $this->assertSame(['searches' => [[
            'collection' => 'docs',
            'q' => '*',
            'vector_query' => 'embedding:([0.5,0.25])',
            'exclude_fields' => 'embedding',
            'per_page' => 20,
            'num_candidates' => 80,
            'filter_by' => 'tenant:=`a && b` && draft:=false',
        ]]], $this->sentJson(0));

        $this->assertCount(1, $results);
        $this->assertSame('Found', $results[0]->getContent());
        $this->assertSame('url', $results[0]->getSourceType());
        $this->assertEqualsWithDelta(0.8, $results[0]->getScore(), 1e-12);
        $this->assertSame(['tenant' => 'acme', 'year' => 2020], $results[0]->getMetadata());
    }

    public function test_search_uses_the_default_top_k_and_a_minimum_candidate_pool(): void
    {
        $this->store(null, $this->jsonResponse(['results' => [['hits' => []]]]))->search(new SearchRequest([1.0]));

        $search = $this->sentJson(0)['searches'][0];
        $this->assertSame(3, $search['per_page']);
        $this->assertSame(50, $search['num_candidates']);
        $this->assertArrayNotHasKey('filter_by', $search);
    }

    public function test_delete_sends_the_compiled_filter_by_expression(): void
    {
        $this->store(null, $this->jsonResponse(['num_deleted' => 1]))->delete(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            Filter::eq('sourceName', 'a.txt'),
        ));

        $this->assertSame('DELETE', $this->sentRequest(0)->getMethod());
        $this->assertSame('/collections/docs/documents/', $this->sentRequest(0)->getUri()->getPath());
        $this->assertSame('sourceType:=`file` && sourceName:=`a.txt`', $this->sentQuery(0)['filter_by']);
    }

    public function test_filter_value_with_a_backtick_never_reaches_the_server(): void
    {
        $store = $this->store(null);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Typesense filter values cannot contain backticks without changing their meaning.');

        try {
            $store->delete(Filter::eq('sourceName', 'a` || sourceType:=`web'));
        } finally {
            $this->assertSame([], $this->sentRequests);
        }
    }

    public function test_delete_refuses_a_meilisearch_raw_fragment(): void
    {
        $store = $this->store(null);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . MeilisearchVectorStore::class);

        try {
            $store->delete(Filter::raw(MeilisearchVectorStore::class, "sourceType = 'file'"));
        } finally {
            $this->assertSame([], $this->sentRequests);
        }
    }

    protected function storeRejectingInvalidInput(): VectorStoreInterface
    {
        return $this->store(null);
    }

    protected function remoteCallCount(): int
    {
        return count($this->sentRequests);
    }
}
