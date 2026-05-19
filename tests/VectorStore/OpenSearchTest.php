<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;
use OpenSearch\Client;
use OpenSearch\GuzzleClientFactory;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Traits\CheckOpenPort;
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

        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stubs/hello-world.embeddings'), true);
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
        $document->embedding = $this->embedding;
        $document->addMetadata('customProperty', 'customValue');

        $store->addDocument($document);

        $results = $store->similaritySearch($this->embedding);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_elasticsearch_delete_documents(): void
    {
        $store = new OpenSearchVectorStore($this->client, 'test');
        $store->deleteBySource('manual', 'manual');

        $results = $store->similaritySearch($this->embedding);
        $this->assertCount(0, $results);
    }
}
