<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\PineconeVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;
use function count;
use function range;

class PineconeVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected const INDEX_URL = 'https://index.pinecone.test/';

    protected function store(?DocumentSchema $schema = null, Response ...$responses): PineconeVectorStore
    {
        return new PineconeVectorStore(
            key: 'pinecone-secret',
            indexUrl: self::INDEX_URL,
            topK: 3,
            namespace: 'tenant-ns',
            httpClient: $this->recordingClient(...$responses),
            schema: $schema,
        );
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::string('notes'),
        );
    }

    protected function document(string $id, string $content = 'content'): Document
    {
        return (new Document($content))
            ->setId($id)
            ->setEmbedding([0.1, 0.2])
            ->setSourceType('file')
            ->setSourceName('guide.md')
            ->setMetadata(['tenant' => 'acme', 'year' => 2026, 'notes' => 'internal']);
    }

    public function test_upserts_documents_with_opaque_metadata_and_projected_filter_fields(): void
    {
        $this->store($this->schema(), new Response(200))->addDocument($this->document('doc-1'));

        $this->assertSame(['POST https://index.pinecone.test/vectors/upsert'], $this->sentTargets());
        $this->assertSame([
            'namespace' => 'tenant-ns',
            'vectors' => [[
                'id' => 'doc-1',
                'values' => [0.1, 0.2],
                'metadata' => [
                    'content' => 'content',
                    'sourceType' => 'file',
                    'sourceName' => 'guide.md',
                    '_neuron_metadata' => '{"tenant":"acme","year":2026,"notes":"internal"}',
                    'tenant' => 'acme',
                    'year' => 2026,
                ],
            ]],
        ], $this->sentJson(0));
    }

    public function test_sends_the_api_key_and_version_headers(): void
    {
        $this->store(null, new Response(200))->addDocument($this->document('doc-1'));

        $request = $this->sentRequest(0);
        $this->assertSame('pinecone-secret', $request->getHeaderLine('Api-Key'));
        $this->assertSame('2025-04', $request->getHeaderLine('X-Pinecone-API-Version'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function test_upserts_in_chunks_of_one_hundred_vectors(): void
    {
        $documents = array_map(fn (int $i): Document => $this->document("doc-{$i}"), range(1, 201));

        $this->store(null, new Response(200), new Response(200), new Response(200))->addDocuments($documents);

        $this->assertCount(3, $this->sentRequests);
        $this->assertSame([100, 100, 1], [
            count($this->sentJson(0)['vectors']),
            count($this->sentJson(1)['vectors']),
            count($this->sentJson(2)['vectors']),
        ]);
        $this->assertSame('doc-201', $this->sentJson(2)['vectors'][0]['id']);
    }

    public function test_integer_ids_are_sent_as_strings(): void
    {
        $this->store(null, new Response(200))->addDocument($this->document('ignored')->setId(42));

        $this->assertSame('42', $this->sentJson(0)['vectors'][0]['id']);
    }

    public function test_rejects_a_document_violating_the_schema_before_sending_anything(): void
    {
        $document = $this->document('doc-1')->setMetadata(['year' => 2026]);

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document doc-1 is missing required metadata field "tenant".');

        try {
            $this->store($this->schema())->addDocument($document);
        } finally {
            $this->assertSame([], $this->sentRequests);
        }
    }

    public function test_search_sends_the_default_top_k_and_maps_matches(): void
    {
        $store = $this->store($this->schema(), $this->jsonResponse(['matches' => [[
            'id' => 'doc-7',
            'score' => 0.87,
            'values' => [0.3, 0.4],
            'metadata' => [
                'content' => 'Stored content',
                'sourceType' => 'url',
                'sourceName' => 'https://example.test',
                '_neuron_metadata' => '{"tenant":"acme","nested":{"a":1}}',
                'tenant' => 'acme',
            ],
        ]]]));

        $results = $store->search(new SearchRequest([0.3, 0.4]));

        $this->assertSame(['POST https://index.pinecone.test/query'], $this->sentTargets());
        $this->assertSame([
            'namespace' => 'tenant-ns',
            'includeMetadata' => true,
            'includeValues' => true,
            'vector' => [0.3, 0.4],
            'topK' => 3,
        ], $this->sentJson(0));

        $this->assertCount(1, $results);
        $document = $results[0];
        $this->assertSame('doc-7', $document->getId());
        $this->assertSame('Stored content', $document->getContent());
        $this->assertSame([0.3, 0.4], $document->getEmbedding());
        $this->assertSame('url', $document->getSourceType());
        $this->assertSame('https://example.test', $document->getSourceName());
        $this->assertSame(0.87, $document->getScore());
        $this->assertSame(['tenant' => 'acme', 'nested' => ['a' => 1]], $document->getMetadata());
    }

    public function test_search_compiles_filters_and_honors_the_request_top_k(): void
    {
        $store = $this->store($this->schema(), $this->jsonResponse(['matches' => []]));

        $results = $store->search(new SearchRequest(
            [1.0],
            FilterGroup::allOf(Filter::eq('tenant', 'acme'), Filter::gte('year', 2020)),
            topK: 9,
        ));

        $this->assertSame([], $results);
        $body = $this->sentJson(0);
        $this->assertSame(9, $body['topK']);
        $this->assertSame(['$and' => [
            ['tenant' => ['$eq' => 'acme']],
            ['year' => ['$gte' => 2020]],
        ]], $body['filter']);
    }

    public function test_delete_sends_the_compiled_filter_to_the_namespace(): void
    {
        $this->store(null, new Response(200))->delete(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            Filter::eq('sourceName', 'guide.md'),
        ));

        $this->assertSame(['POST https://index.pinecone.test/vectors/delete'], $this->sentTargets());
        $this->assertSame([
            'namespace' => 'tenant-ns',
            'filter' => ['$and' => [
                ['sourceType' => ['$eq' => 'file']],
                ['sourceName' => ['$eq' => 'guide.md']],
            ]],
        ], $this->sentJson(0));
    }

    public function test_undeclared_filter_field_is_rejected_before_any_request(): void
    {
        $store = $this->store($this->schema());

        $operations = [
            'search' => fn (): mixed => $store->search(new SearchRequest([1.0], Filter::eq('$or', 'x'))),
            'delete' => fn (): mixed => $store->delete(Filter::eq('$or', 'x')),
        ];

        foreach ($operations as $operation => $call) {
            try {
                $call();
                $this->fail("{$operation} must reject an undeclared filter field.");
            } catch (DocumentSchemaException $exception) {
                $this->assertSame('Filter field "$or" is not declared in the vector store document schema.', $exception->getMessage());
            }
        }

        $this->assertSame([], $this->sentRequests);
    }

    public function test_delete_with_a_raw_filter_for_another_store_never_reaches_the_server(): void
    {
        $store = $this->store();

        try {
            $store->delete(Filter::raw(QdrantVectorStore::class, ['key' => 'tenant']));
            $this->fail('A foreign raw filter must be refused.');
        } catch (VectorStoreException $exception) {
            $this->assertSame(
                'Raw filter targets ' . QdrantVectorStore::class . '; it cannot be compiled for ' . PineconeVectorStore::class . '.',
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $this->sentRequests);
    }

    public function test_server_errors_surface_without_leaking_the_api_key(): void
    {
        $store = $this->store(null, $this->jsonResponse(['message' => 'boom'], 500));

        try {
            $store->search(new SearchRequest([1.0]));
            $this->fail('A server error must surface.');
        } catch (HttpException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
            $this->assertStringNotContainsString('pinecone-secret', $exception->getMessage());
        }
    }

    public function test_every_request_targets_the_configured_index(): void
    {
        $store = $this->store(null, new Response(200), $this->jsonResponse(['matches' => []]), new Response(200));

        $store->addDocument($this->document('doc-1'));
        $store->search(new SearchRequest([1.0]));
        $store->delete(Filter::eq('sourceType', 'file'));

        $this->assertSame(
            ['https://index.pinecone.test/vectors/upsert', 'https://index.pinecone.test/query', 'https://index.pinecone.test/vectors/delete'],
            array_map(static fn (array $entry): string => (string) $entry['request']->getUri(), $this->sentRequests),
        );
        $this->assertSame(['tenant-ns', 'tenant-ns', 'tenant-ns'], array_column([$this->sentJson(0), $this->sentJson(1), $this->sentJson(2)], 'namespace'));
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
