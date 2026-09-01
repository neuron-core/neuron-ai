<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\CheckOpenPort;
use OpenSearch\Client;
use OpenSearch\GuzzleClientFactory;
use PHPUnit\Framework\TestCase;
use function file_get_contents;
use function json_decode;

class OpenSearchTest extends TestCase
{
    use CheckOpenPort;

    protected Client $client;

    protected array $embedding;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 9201)) {
            $this->markTestSkipped('Port 9201 is not open. Skipping test.');
        }

        $this->client = (new GuzzleClientFactory())->create([
            'base_uri' => 'http://localhost:9201',
        ]);

        // Clean up stale data from previous runs
        $this->client->indices()->delete(['index' => 'test', 'ignore_unavailable' => true]);

        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stub/hello-world.embeddings'), true);
    }

    public function test_elasticsearch_instance(): void
    {
        $store = new OpenSearchVectorStore($this->client, 'test');
        $this->assertInstanceOf(VectorStoreInterface::class, $store);
    }

    public function test_add_document_and_search(): void
    {
        $store = new OpenSearchVectorStore($this->client, 'test');

        $document = new Document('Hello World!');
        $document->setEmbedding($this->embedding);
        $document->addMetadata('customProperty', 'customValue');

        $store->addDocument($document);

        $results = $store->search(new SearchRequest($this->embedding));

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    public function test_elasticsearch_delete_documents(): void
    {
        $store = new OpenSearchVectorStore($this->client, 'test');

        $document = new Document('Hello World!');
        $document->setEmbedding($this->embedding);
        $store->addDocument($document);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(0, $results);
    }

    public function test_opensearch_delete_by_type(): void
    {
        $store = new OpenSearchVectorStore($this->client, 'test');

        $document1 = new Document('Hello type A!');
        $document1->setEmbedding($this->embedding);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello type B!');
        $document2->setEmbedding($this->embedding);
        $document2->setSourceType('file');
        $document2->setSourceName('doc.txt');

        $store->addDocuments([$document1, $document2]);
        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
        foreach ($results as $result) {
            $this->assertNotEquals('web', $result->getSourceType());
        }
    }
}
