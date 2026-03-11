<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Traits\CheckOpenPort;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;
use function sleep;

class MeiliSearchTest extends TestCase
{
    use CheckOpenPort;

    protected array $embedding;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 7700)) {
            $this->markTestSkipped('Port 7700 is not open. Skipping test.');
        }

        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stubs/hello-world.embeddings'), true);
    }

    public function test_meilisearchsearch_instance(): void
    {
        $store = new MeilisearchVectorStore('neuron');
        $this->assertInstanceOf(VectorStoreInterface::class, $store);
    }

    public function test_add_document_and_search(): void
    {
        $store = new MeilisearchVectorStore('neuron');

        $document = new Document('Hello World!');
        $document->embedding = $this->embedding;
        $document->addMetadata('customProperty', 'customValue');

        $store->addDocument($document);

        // Wait for Meilisearch to index the document
        sleep(5);

        $results = $store->similaritySearch($this->embedding);

        $this->assertNotEmpty($results);
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_meilisearch_delete_documents(): void
    {
        $store = new MeilisearchVectorStore('neuron');
        $store->deleteBySource('manual', 'manual');

        // Wait for Meilisearch to delete documents
        sleep(5);

        $results = $store->similaritySearch($this->embedding);
        $this->assertCount(0, $results);
    }

    public function test_meilisearch_delete_by_type(): void
    {
        $store = new MeilisearchVectorStore('neuron');

        $document1 = new Document('Hello type A!');
        $document1->embedding = $this->embedding;
        $document1->sourceType = 'web';
        $document1->sourceName = 'page-a';

        $document2 = new Document('Hello type B!');
        $document2->embedding = $this->embedding;
        $document2->sourceType = 'file';
        $document2->sourceName = 'doc.txt';

        $store->addDocuments([$document1, $document2]);

        // Wait for Meilisearch to index the documents
        sleep(5);

        $store->deleteBy('web');

        // Wait for Meilisearch to delete documents
        sleep(5);

        $results = $store->similaritySearch($this->embedding);
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
        $this->assertEquals('Hello type B!', $results[0]->getContent());
    }
}
