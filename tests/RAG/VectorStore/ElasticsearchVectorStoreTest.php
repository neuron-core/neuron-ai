<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;

use function count;
use function array_map;
use function explode;
use function json_decode;
use function range;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Offline contract of the Elasticsearch requests; ElasticsearchTest covers a live cluster.
 */
class ElasticsearchVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected function store(?DocumentSchema $schema, Response ...$responses): ElasticsearchVectorStore
    {
        $client = ClientBuilder::create()
            ->setHosts(['http://es.test:9200'])
            ->setHttpClient($this->recordingPsrClient(...$responses))
            ->build();

        return new ElasticsearchVectorStore($client, 'docs', 3, $schema);
    }

    /**
     * @param array<mixed> $body
     */
    protected function es(array $body = [], int $status = 200): Response
    {
        return $this->jsonResponse($body, $status, ['X-Elastic-Product' => 'Elasticsearch']);
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::floats('ratings'),
            DocumentField::boolean('draft'),
        );
    }

    protected function document(string $content = 'Hello', string $id = 'doc-1'): Document
    {
        return (new Document($content))
            ->setId($id)
            ->setEmbedding([0.1, 0.2])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'year' => 2026, 'note' => 'n']);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function sentNdjson(int $index): array
    {
        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            explode("\n", trim((string) $this->sentRequest($index)->getBody())),
        );
    }

    public function test_first_document_creates_the_index_with_schema_mappings(): void
    {
        $store = $this->store($this->schema(), $this->es([], 404), $this->es(), $this->es(), $this->es());

        $store->addDocument($this->document());

        $this->assertSame([
            'HEAD http://es.test:9200/docs',
            'PUT http://es.test:9200/docs',
            'PUT http://es.test:9200/docs/_doc/doc-1',
            'POST http://es.test:9200/docs/_refresh',
        ], $this->sentTargets());
        $this->assertSame(['mappings' => ['properties' => [
            'embedding' => ['type' => 'dense_vector', 'dims' => 2, 'index' => true, 'similarity' => 'cosine'],
            'content' => ['type' => 'text'],
            'sourceType' => ['type' => 'keyword'],
            'sourceName' => ['type' => 'keyword'],
            '_neuron_metadata' => ['type' => 'text', 'index' => false],
            'tenant' => ['type' => 'keyword'],
            'year' => ['type' => 'long'],
            'ratings' => ['type' => 'double'],
            'draft' => ['type' => 'boolean'],
        ]]], $this->sentJson(1));
        $this->assertSame([
            'embedding' => [0.1, 0.2],
            'content' => 'Hello',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            '_neuron_metadata' => '{"tenant":"acme","year":2026,"note":"n"}',
            'tenant' => 'acme',
            'year' => 2026,
        ], $this->sentJson(2));
    }

    public function test_an_index_created_by_the_store_is_not_remapped_for_later_documents(): void
    {
        $store = $this->store(null, $this->es([], 404), $this->es(), $this->es(), $this->es(), $this->es(), $this->es(), $this->es());

        $store->addDocument($this->document());
        $store->addDocument($this->document());

        $this->assertSame([
            'HEAD http://es.test:9200/docs',
            'PUT http://es.test:9200/docs',
            'PUT http://es.test:9200/docs/_doc/doc-1',
            'POST http://es.test:9200/docs/_refresh',
            'HEAD http://es.test:9200/docs',
            'PUT http://es.test:9200/docs/_doc/doc-1',
            'POST http://es.test:9200/docs/_refresh',
        ], $this->sentTargets());
    }

    public function test_existing_index_gets_a_cosine_dense_vector_mapping_for_the_embedding_size(): void
    {
        $store = $this->store(null, $this->es(), $this->es(['docs' => ['mappings' => []]]), $this->es(), $this->es(), $this->es());

        $store->addDocument($this->document());

        $this->assertSame([
            'HEAD http://es.test:9200/docs',
            'GET http://es.test:9200/docs/_mapping/field/embedding',
            'PUT http://es.test:9200/docs/_mapping',
            'PUT http://es.test:9200/docs/_doc/doc-1',
            'POST http://es.test:9200/docs/_refresh',
        ], $this->sentTargets());
        $this->assertSame(['properties' => ['embedding' => [
            'type' => 'dense_vector',
            'dims' => 2,
            'index' => true,
            'similarity' => 'cosine',
        ]]], $this->sentJson(2));
    }

    public function test_vector_mapping_is_resolved_only_once_per_store_after_a_remap(): void
    {
        $store = $this->store(
            null,
            $this->es(),
            $this->es(['docs' => ['mappings' => []]]),
            $this->es(),
            $this->es(),
            $this->es(),
            $this->es(),
            $this->es(),
            $this->es(),
        );

        $store->addDocument($this->document('One'));
        $store->addDocument($this->document('Two'));

        $this->assertSame([
            'HEAD http://es.test:9200/docs',
            'GET http://es.test:9200/docs/_mapping/field/embedding',
            'PUT http://es.test:9200/docs/_mapping',
            'PUT http://es.test:9200/docs/_doc/doc-1',
            'POST http://es.test:9200/docs/_refresh',
            'HEAD http://es.test:9200/docs',
            'PUT http://es.test:9200/docs/_doc/doc-1',
            'POST http://es.test:9200/docs/_refresh',
        ], $this->sentTargets());
    }

    public function test_matching_vector_mapping_is_left_untouched(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dims' => 2]]]]]];
        $store = $this->store(null, $this->es(), $this->es($mapping), $this->es(), $this->es());

        $store->addDocument($this->document());

        $this->assertNotContains('PUT http://es.test:9200/docs/_mapping', $this->sentTargets());
    }

    public function test_bulk_indexes_every_document_with_its_action_line(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dims' => 2]]]]]];
        $store = $this->store(null, $this->es(), $this->es($mapping), $this->es(['errors' => false]), $this->es());

        $store->addDocuments([$this->document('One', 'one'), $this->document('Two', 'two')]);

        $this->assertSame('POST http://es.test:9200/_bulk', $this->sentTargets()[2]);
        $lines = $this->sentNdjson(2);
        $this->assertCount(4, $lines);
        $this->assertSame(['index' => ['_index' => 'docs', '_id' => 'one']], $lines[0]);
        $this->assertSame('One', $lines[1]['content']);
        $this->assertSame(['index' => ['_index' => 'docs', '_id' => 'two']], $lines[2]);
        $this->assertSame('Two', $lines[3]['content']);
        $this->assertSame('POST http://es.test:9200/docs/_refresh', $this->sentTargets()[3]);
    }

    public function test_each_bulk_request_carries_only_its_own_hundred_documents(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dims' => 2]]]]]];
        $store = $this->store(null, $this->es(), $this->es($mapping), $this->es(['errors' => false]), $this->es(), $this->es(['errors' => false]), $this->es());

        $store->addDocuments(array_map(fn (int $i): Document => $this->document("Document {$i}"), range(1, 101)));

        $this->assertSame('POST http://es.test:9200/_bulk', $this->sentTargets()[4]);
        $this->assertCount(200, $this->sentNdjson(2));
        $this->assertCount(2, $this->sentNdjson(4));
        $this->assertSame('Document 101', $this->sentNdjson(4)[1]['content']);
    }

    public function test_documents_rejected_by_a_bulk_request_raise_an_exception_naming_them(): void
    {
        $mapping = ['docs' => ['mappings' => ['embedding' => ['mapping' => ['embedding' => ['dims' => 2]]]]]];
        $rejection = ['errors' => true, 'items' => [
            ['index' => ['status' => 201]],
            ['index' => ['status' => 400, 'error' => ['type' => 'document_parsing_exception', 'reason' => 'The [cosine] similarity does not support vectors with zero magnitude.']]],
        ]];
        $store = $this->store(null, $this->es(), $this->es($mapping), $this->es($rejection), $this->es());
        $zero = $this->document('Zero')->setEmbedding([0.0, 0.0]);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage("Elasticsearch rejected 1 of 2 documents: [{$zero->getId()}] The [cosine] similarity does not support vectors with zero magnitude.");

        $store->addDocuments([$this->document('Valid'), $zero]);
    }

    public function test_adding_no_documents_sends_nothing(): void
    {
        $this->store(null)->addDocuments([]);

        $this->assertSame([], $this->sentRequests);
    }

    public function test_search_sends_a_filtered_knn_query_and_maps_hits(): void
    {
        $store = $this->store($this->schema(), $this->es(['hits' => ['hits' => [[
            '_id' => 'chunk-9',
            '_score' => 0.93,
            '_source' => [
                'content' => 'Found',
                'sourceType' => 'url',
                'sourceName' => 'https://example.test',
                '_neuron_metadata' => '{"tenant":"acme","note":"n"}',
                'tenant' => 'acme',
            ],
        ]]]]));

        $results = $store->search(new SearchRequest(
            [0.1, 0.2],
            FilterGroup::allOf(Filter::eq('tenant', 'acme'), Filter::neq('sourceType', 'web')),
            topK: 20,
        ));

        $this->assertSame(['POST http://es.test:9200/docs/_search'], $this->sentTargets());
        $this->assertSame([
            'knn' => [
                'field' => 'embedding',
                'query_vector' => [0.1, 0.2],
                'k' => 20,
                'num_candidates' => 80,
                'filter' => ['bool' => [
                    'must' => [
                        ['term' => ['tenant' => 'acme']],
                        ['exists' => ['field' => 'sourceType']],
                    ],
                    'must_not' => [['term' => ['sourceType' => 'web']]],
                ]],
            ],
            'size' => 20,
            'sort' => ['_score' => ['order' => 'desc']],
        ], $this->sentJson(0));

        $this->assertCount(1, $results);
        $this->assertSame('chunk-9', $results[0]->getId());
        $this->assertSame('Found', $results[0]->getContent());
        $this->assertSame('url', $results[0]->getSourceType());
        $this->assertSame('https://example.test', $results[0]->getSourceName());
        $this->assertSame(0.93, $results[0]->getScore());
        $this->assertSame(['tenant' => 'acme', 'note' => 'n'], $results[0]->getMetadata());
    }

    public function test_search_without_filters_uses_the_default_top_k_and_a_minimum_candidate_pool(): void
    {
        $this->store(null, $this->es(['hits' => ['hits' => []]]))->search(new SearchRequest([1.0]));

        $knn = $this->sentJson(0)['knn'];
        $this->assertSame(3, $knn['k']);
        $this->assertSame(50, $knn['num_candidates']);
        $this->assertArrayNotHasKey('filter', $knn);
    }

    public function test_delete_by_query_uses_the_compiled_filter_then_refreshes(): void
    {
        $store = $this->store(null, $this->es(['deleted' => 2]), $this->es());

        $store->delete(Filter::eq('sourceName', 'a.txt'));

        $this->assertSame([
            'POST http://es.test:9200/docs/_delete_by_query',
            'POST http://es.test:9200/docs/_refresh',
        ], $this->sentTargets());
        $this->assertSame(['query' => ['term' => ['sourceName' => 'a.txt']]], $this->sentJson(0));
    }

    public function test_delete_refuses_an_opensearch_raw_fragment_without_contacting_the_cluster(): void
    {
        $store = $this->store(null);

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . OpenSearchVectorStore::class);

        try {
            $store->delete(Filter::raw(OpenSearchVectorStore::class, ['match_all' => []]));
        } finally {
            $this->assertSame([], $this->sentRequests);
        }
    }

    public function test_range_filter_on_a_string_field_is_rejected_before_searching(): void
    {
        $store = $this->store($this->schema());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Filter operator gt requires a numeric field; "tenant" is string.');

        try {
            $store->search(new SearchRequest([1.0], Filter::gt('tenant', 5)));
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
