<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use Elastic\Elasticsearch\ClientBuilder;
use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class ElasticsearchIndexCreationMappingTest extends TestCase
{
    use RecordsVectorStoreRequests;

    protected function es(int $status = 200): Response
    {
        return $this->jsonResponse([], $status, ['X-Elastic-Product' => 'Elasticsearch']);
    }

    protected function store(Response ...$responses): ElasticsearchVectorStore
    {
        $client = ClientBuilder::create()
            ->setHosts(['http://es.test:9200'])
            ->setHttpClient($this->recordingPsrClient(...$responses))
            ->build();

        return new ElasticsearchVectorStore($client, 'docs');
    }

    public function test_creating_the_index_maps_the_embedding_as_a_cosine_dense_vector(): void
    {
        $store = $this->store($this->es(404), $this->es(), $this->es(), $this->es());

        $store->addDocument((new Document('Hello'))->setEmbedding([0.1, 0.2, 0.3]));

        $this->assertSame('PUT http://es.test:9200/docs', $this->sentTargets()[1]);
        $this->assertSame([
            'type' => 'dense_vector',
            'dims' => 3,
            'index' => true,
            'similarity' => 'cosine',
        ], $this->sentJson(1)['mappings']['properties']['embedding'] ?? null);
    }

    public function test_creating_the_index_from_a_batch_maps_the_embedding_as_a_dense_vector(): void
    {
        $store = $this->store($this->es(404), $this->es(), $this->es(), $this->es());

        $store->addDocuments([(new Document('Hello'))->setEmbedding([0.1, 0.2, 0.3])]);

        $this->assertSame('PUT http://es.test:9200/docs', $this->sentTargets()[1]);
        $this->assertSame('dense_vector', $this->sentJson(1)['mappings']['properties']['embedding']['type'] ?? null);
    }
}
