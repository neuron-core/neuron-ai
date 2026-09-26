<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use Elastic\Elasticsearch\ClientBuilder;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class ElasticsearchSizeReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_search_asks_for_top_k_hits(): void
    {
        $response = $this->jsonResponse(['hits' => ['hits' => []]], 200, ['X-Elastic-Product' => 'Elasticsearch']);
        $client = ClientBuilder::create()->setHosts(['http://es.test:9200'])->setHttpClient($this->recordingPsrClient($response))->build();

        (new ElasticsearchVectorStore($client, 'docs'))->search(new SearchRequest([1.0], topK: 25));

        $body = $this->sentJson(0);
        $this->assertSame(25, $body['knn']['k']);
        $this->assertSame(25, $body['size'] ?? null);
    }
}
