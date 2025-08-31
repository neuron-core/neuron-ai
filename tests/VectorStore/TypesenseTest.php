<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\TypesenseVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Traits\CheckOpenPort;
use PHPUnit\Framework\TestCase;
use Typesense\Client;

class TypesenseTest extends TestCase
{
    use CheckOpenPort;

    protected Client $client;

    protected int $vectorDimension = 1024;

    protected array $embedding;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 8108)) {
            $this->markTestSkipped('Port 8108 is not open. Skipping test.');
        }

        // see getting started
        // https://typesense.org/docs/guide/install-typesense.html#option-2-local-machine-self-hosting

        $this->client = new Client([
            'api_key' => 'xyz',
            'nodes' => [
                [
                    'host' => '127.0.0.1',
                    'port' => '8108',
                    'protocol' => 'http'
                ],
            ]
        ]);

        // embedding "Hello World!"
        $this->embedding = \json_decode(\file_get_contents(__DIR__ . '/../Stubs/hello-world.embeddings'), true);
    }

    public function test_typesense_instance(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);
        $this->assertInstanceOf(VectorStoreInterface::class, $store);
    }

    public function test_add_document_and_search(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);

        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = $this->embedding;

        $store->addDocument($document);

        $results = $store->similaritySearch($this->embedding);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_add_documents(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);

        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = $this->embedding;

        $store->addDocuments([$document]);

        $results = $store->similaritySearch($this->embedding);

        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->metadata['customProperty'], $results[0]->metadata['customProperty']);
    }

    public function test_delete_documents(): void
    {
        $store = new TypesenseVectorStore($this->client, 'test', $this->vectorDimension);

        $store->deleteBySource('manual', 'manual');

        $results = $store->similaritySearch($this->embedding);
        $this->assertCount(0, $results);
    }
}
