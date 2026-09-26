<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use OpenSearch\Client as OpenSearchClient;
use OpenSearch\EndpointFactory;
use OpenSearch\RequestFactory;
use OpenSearch\Serializers\SmartSerializer;
use OpenSearch\TransportFactory;
use PHPUnit\Framework\TestCase;

use function count;
use function explode;
use function json_decode;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Offline contract of the OpenSearch requests; OpenSearchTest covers a live cluster.
 */
class OpenSearchVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected function store(?DocumentSchema $schema, Response ...$responses): OpenSearchVectorStore
    {
        $httpFactory = new HttpFactory();
        $serializer = new SmartSerializer();
        $transport = (new TransportFactory())
            ->setHttpClient(new Client(['base_uri' => 'http://os.test:9200', 'handler' => $this->recordingHandler(...$responses)]))
            ->setRequestFactory(new RequestFactory($httpFactory, $httpFactory, $httpFactory, $serializer))
            ->create();

        return new OpenSearchVectorStore(new OpenSearchClient($transport, new EndpointFactory($serializer), []), 'docs', 3, $schema);
    }

    protected function document(string $content = 'Hello'): Document
    {
        return (new Document($content))
            ->setEmbedding([0.1, 0.2, 0.3])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme']);
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(DocumentField::string('tenant')->required()->filterable());
    }

    public function test_first_document_creates_a_knn_index_sized_to_the_embedding(): void
    {
        $store = $this->store($this->schema(), new Response(404), $this->jsonResponse(), $this->jsonResponse(), $this->jsonResponse());

        $store->addDocument($this->document());

        $this->assertSame([
            'HEAD http://os.test:9200/docs',
            'PUT http://os.test:9200/docs',
            'POST http://os.test:9200/docs/_doc',
            'POST http://os.test:9200/docs/_refresh',
        ], $this->sentTargets());

        $index = $this->sentJson(1);
        $this->assertSame(['index' => ['knn' => true]], $index['settings']);
        $this->assertSame([
            'type' => 'knn_vector',
            'dimension' => 3,
            'index' => true,
            'method' => [
                'name' => 'hnsw',
                'engine' => 'lucene',
                'space_type' => 'cosinesimil',
                'parameters' => ['encoder' => ['name' => 'sq', 'parameters' => ['bits' => 7]]],
            ],
        ], $index['mappings']['properties']['embedding']);
        $this->assertSame(['type' => 'keyword'], $index['mappings']['properties']['tenant']);
        $this->assertSame(['type' => 'text', 'index' => false], $index['mappings']['properties']['_neuron_metadata']);

        $this->assertSame([
            'embedding' => [0.1, 0.2, 0.3],
            'content' => 'Hello',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            '_neuron_metadata' => '{"tenant":"acme"}',
            'tenant' => 'acme',
        ], $this->sentJson(2));
    }

    public function test_the_new_index_maps_each_schema_field_type_so_filters_compare_natively(): void
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
        $store = $this->store($schema, new Response(404), $this->jsonResponse(), $this->jsonResponse(), $this->jsonResponse());

        $store->addDocument($this->document());

        $properties = $this->sentJson(1)['mappings']['properties'];
        unset($properties['content'], $properties['sourceType'], $properties['sourceName'], $properties['_neuron_metadata'], $properties['embedding']);
        $this->assertSame([
            'tenant' => ['type' => 'keyword'],
            'tags' => ['type' => 'keyword'],
            'year' => ['type' => 'long'],
            'versions' => ['type' => 'long'],
            'rating' => ['type' => 'double'],
            'scores' => ['type' => 'double'],
            'draft' => ['type' => 'boolean'],
            'flags' => ['type' => 'boolean'],
        ], $properties);
    }

    public function test_adding_no_documents_does_not_contact_the_cluster(): void
    {
        $store = $this->store(null);

        $this->assertSame($store, $store->addDocuments([]));
        $this->assertSame([], $this->sentRequests);
    }

    public function test_bulk_indexes_all_documents_in_one_request(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dimension' => 3]]]]]];
        $store = $this->store(null, new Response(200), $this->jsonResponse($mapping), $this->jsonResponse(['errors' => false]), $this->jsonResponse());

        $store->addDocuments([$this->document('One'), $this->document('Two')]);

        $this->assertSame([
            'HEAD http://os.test:9200/docs',
            'GET http://os.test:9200/docs/_mapping/field/embedding',
            'POST http://os.test:9200/_bulk',
            'POST http://os.test:9200/docs/_refresh',
        ], $this->sentTargets());

        $lines = explode("\n", trim((string) $this->sentRequest(2)->getBody()));
        $this->assertCount(4, $lines);
        $this->assertSame(['index' => ['_index' => 'docs']], json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('Two', json_decode($lines[3], true, flags: JSON_THROW_ON_ERROR)['content']);
    }

    public function test_existing_index_with_another_dimension_gets_a_new_knn_mapping(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dimension' => 1536]]]]]];
        $store = $this->store(null, new Response(200), $this->jsonResponse($mapping), $this->jsonResponse(), $this->jsonResponse(), $this->jsonResponse());

        $store->addDocument($this->document());

        $this->assertSame('PUT http://os.test:9200/docs/_mapping', $this->sentTargets()[2]);
        $this->assertSame(['properties' => ['embedding' => [
            'type' => 'knn_vector',
            'dimension' => 3,
            'index' => true,
            'method' => [
                'name' => 'hnsw',
                'engine' => 'lucene',
                'space_type' => 'cosinesimil',
                'parameters' => ['encoder' => ['name' => 'sq', 'parameters' => ['bits' => 7]]],
            ],
        ]]], $this->sentJson(2));
    }

    public function test_vector_mapping_is_resolved_only_once_per_store_after_a_remap(): void
    {
        $store = $this->store(
            null,
            new Response(200),
            $this->jsonResponse(['docs' => ['mappings' => []]]),
            $this->jsonResponse(),
            $this->jsonResponse(),
            $this->jsonResponse(),
            new Response(200),
            $this->jsonResponse(),
            $this->jsonResponse(),
        );

        $store->addDocument($this->document('One'));
        $store->addDocument($this->document('Two'));

        $this->assertSame([
            'HEAD http://os.test:9200/docs',
            'GET http://os.test:9200/docs/_mapping/field/embedding',
            'PUT http://os.test:9200/docs/_mapping',
            'POST http://os.test:9200/docs/_doc',
            'POST http://os.test:9200/docs/_refresh',
            'HEAD http://os.test:9200/docs',
            'POST http://os.test:9200/docs/_doc',
            'POST http://os.test:9200/docs/_refresh',
        ], $this->sentTargets());
    }

    public function test_search_puts_filters_inside_the_knn_clause_and_maps_hits(): void
    {
        $store = $this->store($this->schema(), $this->jsonResponse(['hits' => ['hits' => [[
            '_score' => 0.77,
            '_source' => [
                'content' => 'Found',
                'sourceType' => 'url',
                'sourceName' => 'b',
                '_neuron_metadata' => '{"tenant":"acme"}',
            ],
        ]]]]));

        $results = $store->search(new SearchRequest([0.1, 0.2, 0.3], FilterGroup::anyOf(
            Filter::eq('tenant', 'acme'),
            Filter::eq('sourceType', 'url'),
        )));

        $this->assertSame(['POST http://os.test:9200/docs/_search'], $this->sentTargets());
        $this->assertSame(['knn' => ['embedding' => [
            'vector' => [0.1, 0.2, 0.3],
            'k' => 50,
            'filter' => ['bool' => [
                'should' => [
                    ['term' => ['tenant' => 'acme']],
                    ['term' => ['sourceType' => 'url']],
                ],
                'minimum_should_match' => 1,
            ]],
        ]]], $this->sentJson(0)['query']);

        $this->assertCount(1, $results);
        $this->assertSame('Found', $results[0]->getContent());
        $this->assertSame(0.77, $results[0]->getScore());
        $this->assertSame(['tenant' => 'acme'], $results[0]->getMetadata());
    }

    public function test_delete_by_query_uses_the_compiled_filter_then_refreshes(): void
    {
        $store = $this->store(null, $this->jsonResponse(['deleted' => 1]), $this->jsonResponse());

        $store->delete(Filter::neq('sourceType', 'web'));

        $this->assertSame([
            'POST http://os.test:9200/docs/_delete_by_query',
            'POST http://os.test:9200/docs/_refresh',
        ], $this->sentTargets());
        $this->assertSame(['query' => ['bool' => [
            'must' => [['exists' => ['field' => 'sourceType']]],
            'must_not' => [['term' => ['sourceType' => 'web']]],
        ]]], $this->sentJson(0));
    }

    public function test_delete_refuses_an_elasticsearch_raw_fragment_without_contacting_the_cluster(): void
    {
        $store = $this->store(null);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage(
            'Raw filter targets ' . ElasticsearchVectorStore::class . '; it cannot be compiled for ' . OpenSearchVectorStore::class . '.'
        );

        try {
            $store->delete(Filter::raw(ElasticsearchVectorStore::class, ['match_all' => []]));
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
