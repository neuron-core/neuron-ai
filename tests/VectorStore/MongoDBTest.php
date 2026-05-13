<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use MongoDB\Client;
use MongoDB\Exception\SearchNotSupportedException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MongoDBVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Traits\CheckOpenPort;
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
            $this->store->similaritySearch([0, 0, 0]);
        } catch (SearchNotSupportedException $e) {
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
        $document->embedding = [1, 0, 0];

        $this->store->addDocument($document);

        $results = $this->store->similaritySearch([1, 0, 0]);

        $this->assertCount(1, $results);
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_add_multiple_documents_and_search(): void
    {
        $document1 = new Document('Hello!');
        $document1->addMetadata('key', 'value1');
        $document1->embedding = [1, 0, 0];

        $document2 = new Document('Hello 2!');
        $document2->embedding = [0, 1, 0];

        $this->store->addDocuments([$document1, $document2]);

        $results = $this->store->similaritySearch([1, 0, 0]);

        $this->assertCount(2, $results);
        $this->assertEquals($document1->getContent(), $results[0]->getContent());
        $this->assertEquals('value1', $results[0]->metadata['key']);
    }

    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->sourceType = 'manual';
        $document->sourceName = 'manual';
        $document->embedding = [1, 0, 0];

        $document2 = new Document('Hello 2!');
        $document2->sourceType = 'manual';
        $document2->sourceName = 'manual';
        $document2->embedding = [0, 1, 0];

        $this->store->addDocuments([$document, $document2]);
        $this->store->deleteBy('manual', 'manual');

        $results = $this->store->similaritySearch([1, 0, 0]);
        $this->assertCount(0, $results);
    }

    public function test_delete_by_type(): void
    {
        $document1 = new Document('Hello type A!');
        $document1->sourceType = 'web';
        $document1->sourceName = 'page-a';
        $document1->embedding = [1, 0, 0];

        $document2 = new Document('Hello type B!');
        $document2->sourceType = 'web';
        $document2->sourceName = 'page-b';
        $document2->embedding = [0, 1, 0];

        $document3 = new Document('Hello type C!');
        $document3->sourceType = 'file';
        $document3->sourceName = 'doc.txt';
        $document3->embedding = [0, 0, 1];

        $this->store->addDocuments([$document1, $document2, $document3]);
        $this->store->deleteBy('web');

        $results = $this->store->similaritySearch([1, 0, 0]);
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
    }
}
