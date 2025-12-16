<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\Tests\Traits\CheckOpenPort;
use PHPUnit\Framework\TestCase;

class ChromaDBTest extends TestCase
{
    use CheckOpenPort;

    protected ChromaVectorStore $store;

    public function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 8000)) {
            $this->markTestSkipped("ChromaDB not available on port 8000. Skipping test.");
        }

        $this->store = new ChromaVectorStore('neuron-ai');
    }

    /**
     * @throws HttpException
     */
    protected function tearDown(): void
    {
        $this->store->destroy();
    }

    /**
     * @throws HttpException
     */
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

    /**
     * @throws HttpException
     */
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

    /**
     * @throws HttpException
     */
    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->embedding = [1, 2, 3];

        $document2 = new Document('Hello 2!');
        $document2->embedding = [3, 4, 5];

        $this->store->addDocuments([$document, $document2]);
        $this->store->deleteBySource('manual', 'manual');

        $results = $this->store->similaritySearch([1, 2, 3]);
        $this->assertCount(0, $results);
    }
}
