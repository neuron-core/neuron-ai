<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use OpenSearch\Client as OpenSearchClient;
use OpenSearch\EndpointFactory;
use OpenSearch\RequestFactory;
use OpenSearch\Serializers\SmartSerializer;
use OpenSearch\TransportFactory;
use PHPUnit\Framework\TestCase;

class OpenSearchReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    protected function store(int $topK): OpenSearchVectorStore
    {
        $httpFactory = new HttpFactory();
        $serializer = new SmartSerializer();
        $transport = (new TransportFactory())
            ->setHttpClient(new Client([
                'base_uri' => 'http://os.test:9200',
                'handler' => $this->recordingHandler($this->jsonResponse(['hits' => ['hits' => []]])),
            ]))
            ->setRequestFactory(new RequestFactory($httpFactory, $httpFactory, $httpFactory, $serializer))
            ->create();

        return new OpenSearchVectorStore(new OpenSearchClient($transport, new EndpointFactory($serializer), []), 'docs', topK: $topK);
    }

    public function test_search_limits_hits_to_the_store_top_k(): void
    {
        $this->store(4)->search(new SearchRequest([1.0]));

        $this->assertSame(4, $this->sentJson(0)['size'] ?? null);
    }

    public function test_search_limits_hits_to_the_request_top_k_above_the_default_page(): void
    {
        $this->store(4)->search(new SearchRequest([1.0], topK: 25));

        $this->assertSame(25, $this->sentJson(0)['size'] ?? null);
    }
}
