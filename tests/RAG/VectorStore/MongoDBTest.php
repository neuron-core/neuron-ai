<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use Exception;
use MongoDB\Client;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\CheckOpenPort;
use PHPUnit\Framework\TestCase;
use function uniqid;

class MongoDBTest extends TestCase
{
    use CheckOpenPort;

    protected MongoDBVectorStore $store;

    protected string $collectionName;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 27017)) {
            $this->markTestSkipped('MongoDB not available on port 27017. Skipping test.');
        }

        $client = new Client('mongodb://127.0.0.1:27017');

        $this->collectionName = 'test_vectors_' . uniqid();
        $this->store = new MongoDBVectorStore(
            client: $client,
            database: 'neuron_test',
            collectionName: $this->collectionName,
            topK: 4,
        );

        $this->store->setupVectorIndex(dimensions: 3);

        try {
            $this->store->search(new SearchRequest([0, 0, 0]));
        } catch (Exception $e) {
            $this->markTestSkipped($e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) {
            $this->store->dropCollection();
        }
    }

    public function test_mongodb_store_instance(): void
    {
        $this->assertInstanceOf(VectorStoreInterface::class, $this->store);
    }

    public function test_add_document_and_search(): void
    {
        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->setEmbedding([1, 0, 0]);

        $this->store->addDocument($document);

        $results = $this->store->search(new SearchRequest([1, 0, 0]));

        $this->assertCount(1, $results);
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    public function test_add_multiple_documents_and_search(): void
    {
        $document1 = new Document('Hello!');
        $document1->addMetadata('key', 'value1');
        $document1->setEmbedding([1, 0, 0]);

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([0, 1, 0]);

        $this->store->addDocuments([$document1, $document2]);

        $results = $this->store->search(new SearchRequest([1, 0, 0]));

        $this->assertCount(2, $results);
        $this->assertEquals($document1->getContent(), $results[0]->getContent());
        $this->assertEquals('value1', $results[0]->getMetadata()['key']);
    }

    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->setSourceType('manual');
        $document->setSourceName('manual');
        $document->setEmbedding([1, 0, 0]);

        $document2 = new Document('Hello 2!');
        $document2->setSourceType('manual');
        $document2->setSourceName('manual');
        $document2->setEmbedding([0, 1, 0]);

        $this->store->addDocuments([$document, $document2]);
        $this->store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $this->store->search(new SearchRequest([1, 0, 0]));
        $this->assertCount(0, $results);
    }

    public function test_delete_by_type(): void
    {
        $document1 = new Document('Hello type A!');
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');
        $document1->setEmbedding([1, 0, 0]);

        $document2 = new Document('Hello type B!');
        $document2->setSourceType('web');
        $document2->setSourceName('page-b');
        $document2->setEmbedding([0, 1, 0]);

        $document3 = new Document('Hello type C!');
        $document3->setSourceType('file');
        $document3->setSourceName('doc.txt');
        $document3->setEmbedding([0, 0, 1]);

        $this->store->addDocuments([$document1, $document2, $document3]);
        $this->store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $results = $this->store->search(new SearchRequest([1, 0, 0]));
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
    }
}
