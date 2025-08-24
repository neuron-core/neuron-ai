<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\Tests\Traits\CheckOpenPort;
use PHPUnit\Framework\TestCase;

class QdrantVectorStoreTest extends TestCase
{
    use CheckOpenPort;

    public const SERVICE_PORT = 6333;
    public const COLLECTION_NAME = 'neuron-ai';

    public const VECTOR_DIMENSION = 3;
    public const DISTANCE_METRIC = 'Cosine';

    public const SOURCE_TYPE = 'manual';
    public const SOURCE_NAME = 'manual';

    protected QdrantVectorStore $store;

    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', self::SERVICE_PORT)) {
            $this->markTestSkipped(\sprintf("Port %d is not open. Skipping test.", self::SERVICE_PORT));
        }

        $this->store = new QdrantVectorStore(\sprintf("http://127.0.0.1:%d/collections/%s", self::SERVICE_PORT, self::COLLECTION_NAME));
        $this->store->initialize(self::VECTOR_DIMENSION, self::DISTANCE_METRIC, true);
    }

    public function tearDown(): void
    {
        $this->store->destroy();
    }

    public function test_add_document_and_search(): void
    {
        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = [1, 2, 3];

        $this->store->addDocument($document);

        $results = $this->store->similaritySearch([1, 2, 3]);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_add_multiple_document_and_search(): void
    {
        $document = new Document('Hello!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = [1, 2, 3];

        $document2 = new Document('Hello 2!');
        $document2->embedding = [3, 4, 5];

        $this->store->addDocuments([$document, $document2]);

        $results = $this->store->similaritySearch([1, 2, 3]);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->sourceType = self::SOURCE_TYPE;
        $document->sourceName = self::SOURCE_NAME;
        $document->embedding = [1, 2, 3];

        $document2 = new Document('Hello 2!');
        $document2->sourceType = self::SOURCE_TYPE;
        $document2->sourceName = self::SOURCE_NAME;
        $document2->embedding = [3, 4, 5];

        $this->store->addDocuments([$document, $document2]);
        $this->store->deleteBySource(self::SOURCE_TYPE, self::SOURCE_NAME);

        $results = $this->store->similaritySearch([1, 2, 3]);
        $this->assertCount(0, $results);
    }
}
