<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use NeuronAI\Tests\RAG\VectorStore\Stub\RejectsInvalidInputBeforeRemoteCalls;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_slice;
use function count;
use function range;

/**
 * Offline contract of the Meilisearch REST calls; MeiliSearchTest covers a live server.
 */
class MeilisearchVectorStoreTest extends TestCase
{
    use RecordsVectorStoreRequests;
    use RejectsInvalidInputBeforeRemoteCalls;

    protected const HOST = 'http://meili.test:7700';

    protected const SETUP_REQUESTS = 5;

    protected function store(?string $key = null, ?DocumentSchema $schema = null, Response ...$responses): MeilisearchVectorStore
    {
        return new MeilisearchVectorStore(
            indexUid: 'docs',
            host: self::HOST . '/',
            key: $key,
            embedder: 'custom',
            topK: 3,
            dimension: 4,
            httpClient: $this->recordingClient(new Response(200), ...$this->configurationResponses(), ...$responses),
            schema: $schema,
        );
    }

    /**
     * @return Response[]
     */
    protected function configurationResponses(): array
    {
        return [
            $this->jsonResponse(['taskUid' => 11]),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['taskUid' => 12]),
            $this->jsonResponse(['status' => 'succeeded']),
        ];
    }

    protected function schema(): DocumentSchema
    {
        return DocumentSchema::of(
            DocumentField::string('tenant')->required()->filterable(),
            DocumentField::integer('year')->filterable(),
            DocumentField::string('notes'),
        );
    }

    public function test_configures_an_existing_index_with_the_embedder_and_filterable_attributes(): void
    {
        $this->store(null, $this->schema());

        $this->assertSame([
            'GET http://meili.test:7700/indexes/docs',
            'PATCH http://meili.test:7700/indexes/docs/settings/embedders',
            'GET http://meili.test:7700/tasks/11',
            'PUT http://meili.test:7700/indexes/docs/settings/filterable-attributes',
            'GET http://meili.test:7700/tasks/12',
        ], $this->sentTargets());
        $this->assertSame(
            ['custom' => ['dimensions' => 4, 'source' => 'userProvided', 'binaryQuantized' => false]],
            $this->sentJson(1),
        );
        $this->assertSame(['sourceType', 'sourceName', 'tenant', 'year'], $this->sentJson(3));
    }

    public function test_creates_the_index_when_it_cannot_be_retrieved(): void
    {
        new MeilisearchVectorStore(
            indexUid: 'docs',
            host: self::HOST,
            httpClient: $this->recordingClient(
                $this->jsonResponse(['code' => 'index_not_found'], 404),
                $this->jsonResponse(['taskUid' => 10]),
                $this->jsonResponse(['status' => 'succeeded']),
                ...$this->configurationResponses(),
            ),
        );

        $this->assertSame([
            'GET http://meili.test:7700/indexes/docs',
            'POST http://meili.test:7700/indexes',
            'GET http://meili.test:7700/tasks/10',
        ], array_slice($this->sentTargets(), 0, 3));
        $this->assertSame(['uid' => 'docs', 'primaryKey' => 'id'], $this->sentJson(1));
    }

    public function test_bearer_key_is_sent_on_every_request_only_when_configured(): void
    {
        $this->store('meili-master-key');

        foreach ($this->sentRequests as $entry) {
            $this->assertSame('Bearer meili-master-key', $entry['request']->getHeaderLine('Authorization'));
        }

        $this->sentRequests = [];
        $this->store();

        $this->assertFalse($this->sentRequest(0)->hasHeader('Authorization'));
    }

    public function test_adds_documents_with_user_provided_vectors(): void
    {
        $document = (new Document('Hello'))
            ->setId('doc-1')
            ->setEmbedding([0.5, 0.25])
            ->setSourceType('file')
            ->setSourceName('a.txt')
            ->setMetadata(['tenant' => 'acme', 'notes' => 'hidden']);

        $this->store(null, $this->schema(), new Response(202))->addDocument($document);

        $this->assertSame('PUT http://meili.test:7700/indexes/docs/documents', $this->sentTargets()[self::SETUP_REQUESTS]);
        $this->assertSame([[
            'id' => 'doc-1',
            'content' => 'Hello',
            'sourceType' => 'file',
            'sourceName' => 'a.txt',
            '_neuron_metadata' => '{"tenant":"acme","notes":"hidden"}',
            'tenant' => 'acme',
            '_vectors' => ['default' => ['embeddings' => [0.5, 0.25], 'regenerate' => false]],
        ]], $this->sentJson(self::SETUP_REQUESTS));
    }

    public function test_adds_documents_in_chunks_of_one_hundred(): void
    {
        $documents = array_map(
            static fn (int $i): Document => (new Document("doc {$i}"))->setEmbedding([1, 0]),
            range(1, 101),
        );

        $this->store(null, null, new Response(202), new Response(202))->addDocuments($documents);

        $this->assertSame([
            'PUT http://meili.test:7700/indexes/docs/documents',
            'PUT http://meili.test:7700/indexes/docs/documents',
        ], array_slice($this->sentTargets(), self::SETUP_REQUESTS));
        $this->assertCount(100, $this->sentJson(self::SETUP_REQUESTS));
        $this->assertSame('doc 101', $this->sentJson(self::SETUP_REQUESTS + 1)[0]['content']);
    }

    public function test_search_sends_a_pure_semantic_query_and_maps_hits(): void
    {
        $store = $this->store(null, $this->schema(), $this->jsonResponse(['hits' => [[
            'id' => 'doc-9',
            'content' => 'Found',
            'sourceType' => 'url',
            'sourceName' => 'https://example.test',
            '_neuron_metadata' => '{"tenant":"acme","notes":"n"}',
            'tenant' => 'acme',
            '_vectors' => ['default' => ['embeddings' => [[0.1, 0.9]], 'regenerate' => false]],
            '_rankingScore' => 0.66,
        ]]]));

        $results = $store->search(new SearchRequest(
            [0.1, 0.9],
            FilterGroup::allOf(Filter::eq('tenant', "o'hara"), Filter::gte('year', 2020)),
        ));

        $this->assertSame('POST http://meili.test:7700/indexes/docs/search', $this->sentTargets()[self::SETUP_REQUESTS]);
        $this->assertSame([
            'vector' => [0.1, 0.9],
            'limit' => 3,
            'retrieveVectors' => true,
            'showRankingScore' => true,
            'hybrid' => ['semanticRatio' => 1, 'embedder' => 'custom'],
            'filter' => "tenant = 'o\\'hara' AND year >= 2020",
        ], $this->sentJson(self::SETUP_REQUESTS));

        $this->assertCount(1, $results);
        $this->assertSame('doc-9', $results[0]->getId());
        $this->assertSame('Found', $results[0]->getContent());
        $this->assertSame([0.1, 0.9], $results[0]->getEmbedding());
        $this->assertSame('url', $results[0]->getSourceType());
        $this->assertSame(0.66, $results[0]->getScore());
        $this->assertSame(['tenant' => 'acme', 'notes' => 'n'], $results[0]->getMetadata());
    }

    public function test_search_honors_a_request_top_k_below_the_default(): void
    {
        $this->store(null, null, $this->jsonResponse(['hits' => []]))->search(new SearchRequest([1.0], topK: 1));

        $body = $this->sentJson(self::SETUP_REQUESTS);
        $this->assertSame(1, $body['limit']);
        $this->assertArrayNotHasKey('filter', $body);
    }

    public function test_delete_posts_the_compiled_filter_expression(): void
    {
        $this->store(null, null, $this->jsonResponse(['taskUid' => 13]))->delete(FilterGroup::allOf(
            Filter::eq('sourceType', 'file'),
            Filter::eq('sourceName', "x' OR sourceType = 'y"),
        ));

        $this->assertSame('POST http://meili.test:7700/indexes/docs/documents/delete', $this->sentTargets()[self::SETUP_REQUESTS]);
        $this->assertSame(
            ['filter' => "sourceType = 'file' AND sourceName = 'x\\' OR sourceType = \\'y'"],
            $this->sentJson(self::SETUP_REQUESTS),
        );
    }

    public function test_delete_on_a_non_filterable_field_never_reaches_the_server(): void
    {
        $store = $this->store(null, $this->schema());

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('Document field "notes" is not filterable.');

        try {
            $store->delete(Filter::eq('notes', 'x'));
        } finally {
            $this->assertCount(self::SETUP_REQUESTS, $this->sentRequests);
        }
    }

    public function test_delete_refuses_a_typesense_raw_fragment(): void
    {
        $store = $this->store();

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('Raw filter targets ' . TypesenseVectorStore::class);

        try {
            $store->delete(Filter::raw(TypesenseVectorStore::class, 'sourceType:=`file`'));
        } finally {
            $this->assertCount(self::SETUP_REQUESTS, $this->sentRequests);
        }
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
