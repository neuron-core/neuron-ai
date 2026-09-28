<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ElasticsearchVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\CheckOpenPort;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_map;
use function count;
use function file_get_contents;
use function json_decode;
use function range;

class ElasticsearchTest extends TestCase
{
    use CheckOpenPort;

    protected Client $client;

    protected array $embedding;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 9200)) {
            $this->markTestSkipped('Port 9200 is not open. Skipping test.');
        }

        $this->client = ClientBuilder::create()->build();

        // Clean up stale data from previous runs
        $this->client->indices()->delete(['index' => 'test', 'ignore_unavailable' => true]);

        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stub/hello-world.embeddings'), true);
    }

    public function test_elasticsearch_instance(): void
    {
        $store = new ElasticsearchVectorStore($this->client, 'test');
        $this->assertInstanceOf(VectorStoreInterface::class, $store);
    }

    public function test_add_document_and_search(): void
    {
        $store = new ElasticsearchVectorStore($this->client, 'test');

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
        $store = new ElasticsearchVectorStore($this->client, 'test');

        $document = new Document('Hello World!');
        $document->setEmbedding($this->embedding);
        $store->addDocument($document);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(0, $results);
    }

    public function test_elasticsearch_delete_by_type(): void
    {
        $store = new ElasticsearchVectorStore($this->client, 'test');

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
        $this->assertSame('file', $results[0]->getSourceType());
    }

    public function test_every_document_is_indexed_once_across_bulk_requests(): void
    {
        $store = new ElasticsearchVectorStore($this->client, 'test');

        $store->addDocuments(array_map(fn (int $i): Document => (new Document("Document {$i}"))->setEmbedding($this->embedding), range(1, 101)));

        $this->assertSame(101, $this->client->count(['index' => 'test'])['count']);
    }

    public function test_a_document_rejected_by_the_cluster_raises_an_exception(): void
    {
        $store = new ElasticsearchVectorStore($this->client, 'test');
        $store->addDocument((new Document('Hello World!'))->setEmbedding($this->embedding));
        $zero = (new Document('Zero'))->setEmbedding(array_fill(0, count($this->embedding), 0.0));

        try {
            $store->addDocuments([(new Document('Valid'))->setEmbedding($this->embedding), $zero]);
            $this->fail('The rejected document must raise an exception.');
        } catch (VectorStoreException $exception) {
            $this->assertStringStartsWith("Elasticsearch rejected 1 of 2 documents: [{$zero->getId()}]", $exception->getMessage());
        }

        $this->assertSame(2, $this->client->count(['index' => 'test'])['count']);
    }
}
