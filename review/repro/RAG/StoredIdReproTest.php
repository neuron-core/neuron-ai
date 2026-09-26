<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use Elastic\Elasticsearch\ClientBuilder;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

class StoredIdReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_typesense_search_results_keep_the_stored_document_id(): void
    {
        $store = new TypesenseVectorStore(new Client([
            'api_key' => 'k',
            'nodes' => [['host' => 'ts.test', 'port' => '8108', 'protocol' => 'http']],
            'client' => $this->recordingPsrClient($this->jsonResponse(['results' => [['hits' => [[
                'document' => ['id' => 'doc-5', 'content' => 'Found', 'sourceType' => 'url', 'sourceName' => 'b'],
                'vector_distance' => 0.2,
            ]]]]])),
            'num_retries' => 0,
            'log_level' => 600,
        ]), 'docs', 2);

        $this->assertSame('doc-5', $store->search(new SearchRequest([1.0]))[0]->getId());
    }

    public function test_elasticsearch_indexes_with_the_document_id_and_reads_it_back(): void
    {
        $es = ['X-Elastic-Product' => 'Elasticsearch'];
        $client = ClientBuilder::create()
            ->setHosts(['http://es.test:9200'])
            ->setHttpClient($this->recordingPsrClient(
                $this->jsonResponse([], 404, $es),
                $this->jsonResponse(['acknowledged' => true], 200, $es),
                $this->jsonResponse(['result' => 'created'], 201, $es),
                $this->jsonResponse([], 200, $es),
                $this->jsonResponse(['hits' => ['hits' => [[
                    '_id' => 'doc-5',
                    '_score' => 0.9,
                    '_source' => ['content' => 'Found', 'sourceType' => 'url', 'sourceName' => 'b'],
                ]]]], 200, $es),
            ))
            ->build();
        $store = new ElasticsearchVectorStore($client, 'docs', 2);

        $store->addDocument((new Document('Found'))->setId('doc-5')->setEmbedding([0.1, 0.2]));

        $this->assertStringContainsString('/docs/_doc/doc-5', (string) $this->sentRequest(2)->getUri());
        $this->assertSame('doc-5', $store->search(new SearchRequest([1.0]))[0]->getId());
    }
}
