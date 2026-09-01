<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use GuzzleHttp\Client;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\CheckOpenPort;
use PHPUnit\Framework\TestCase;
use function file_get_contents;
use function in_array;
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

        // Clean up stale data from previous runs
        // MeiliSearch delete is async — we must wait for the task to complete
        // before proceeding, otherwise the index may be auto-recreated without
        // embedder settings when documents are added.
        $client = new Client();
        $response = $client->delete('http://localhost:7700/indexes/neuron');
        $task = json_decode($response->getBody()->getContents(), true);

        if (isset($task['taskUid'])) {
            for ($i = 0; $i < 10; $i++) {
                sleep(1);
                $taskResponse = $client->get('http://localhost:7700/tasks/' . $task['taskUid']);
                $taskStatus = json_decode($taskResponse->getBody()->getContents(), true);
                if (in_array($taskStatus['status'] ?? '', ['succeeded', 'failed'], true)) {
                    break;
                }
            }
        }

        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stub/hello-world.embeddings'), true);
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
        $document->setEmbedding($this->embedding);
        $document->addMetadata('customProperty', 'customValue');

        $store->addDocument($document);

        // Wait for Meilisearch to index the document
        sleep(5);

        $results = $store->search(new SearchRequest($this->embedding));

        $this->assertNotEmpty($results);
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    public function test_meilisearch_delete_documents(): void
    {
        $store = new MeilisearchVectorStore('neuron');

        $document = new Document('Hello World!');
        $document->setEmbedding($this->embedding);
        $store->addDocument($document);

        // Wait for Meilisearch to index the document
        sleep(5);

        $store->delete(FilterGroup::and(
            Filter::eq('sourceType', 'manual'),
            Filter::eq('sourceName', 'manual'),
        ));

        // Wait for Meilisearch to delete documents
        sleep(5);

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(0, $results);
    }

    public function test_meilisearch_delete_by_type(): void
    {
        $store = new MeilisearchVectorStore('neuron');

        $document1 = new Document('Hello type A!');
        $document1->setEmbedding($this->embedding);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello type B!');
        $document2->setEmbedding($this->embedding);
        $document2->setSourceType('file');
        $document2->setSourceName('doc.txt');

        $store->addDocuments([$document1, $document2]);

        // Wait for Meilisearch to index the documents
        sleep(5);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        // Wait for Meilisearch to delete documents
        sleep(5);

        $results = $store->search(new SearchRequest($this->embedding));
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
        $this->assertEquals('Hello type B!', $results[0]->getContent());
    }

    public function test_similarity_search_with_filters(): void
    {
        $store = new MeilisearchVectorStore('neuron');

        $document1 = new Document('Hello type A!');
        $document1->setEmbedding($this->embedding);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello type B!');
        $document2->setEmbedding($this->embedding);
        $document2->setSourceType('file');
        $document2->setSourceName('doc.txt');

        $store->addDocuments([$document1, $document2]);

        // Wait for Meilisearch to index the documents
        sleep(5);

        $results = $store->search(new SearchRequest(
            $this->embedding,
            filters: FilterGroup::and(Filter::eq('sourceType', 'file')),
        ));

        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
    }
}
