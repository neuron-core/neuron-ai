<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function json_decode;

class MemoryVectorStoreTest extends TestCase
{
    protected array $embedding;

    protected function setUp(): void
    {
        // embedding "Hello World!"
        $this->embedding = json_decode(file_get_contents(__DIR__ . '/../Stubs/hello-world.embeddings'), true);
    }

    public function test_memory_store_instance(): void
    {
        $store = new MemoryVectorStore();
        $this->assertInstanceOf(VectorStoreInterface::class, $store);
    }

    public function test_add_document_and_search(): void
    {
        $this->expectNotToPerformAssertions();
        $document = new Document('Hello World!');
        $document->setEmbedding($this->embedding);

        $store = new MemoryVectorStore();
        $store->addDocument($document);

        $store->search(new SearchRequest($this->embedding));
    }

    public function test_similarity_search_with_scores(): void
    {
        $store = new MemoryVectorStore();

        $doc1 = new Document("Document 1");
        $doc1->setEmbedding([1, 0]);
        $doc2 = new Document("Document 2");
        $doc2->setEmbedding([0, 1]);
        $doc3 = new Document("Document 3");
        $doc3->setEmbedding([0.5, 0.5]);

        $store->addDocuments([$doc1, $doc2, $doc3]);

        $results = $store->search(new SearchRequest([1, 0]));

        $this->assertCount(3, $results);
        $this->assertGreaterThanOrEqual($results[1]->getScore(), $results[0]->getScore());
        $this->assertGreaterThanOrEqual($results[2]->getScore(), $results[1]->getScore());
    }

    public function test_search_scores_do_not_mutate_stored_or_previous_documents(): void
    {
        $source = (new Document('Document'))
            ->setEmbedding([1, 0])
            ->setScore(0.25);
        $store = new MemoryVectorStore();
        $store->addDocument($source);

        $first = $store->search(new SearchRequest([1, 0]))[0];
        $firstScore = $first->getScore();
        $store->search(new SearchRequest([0, 1]));

        $this->assertSame(0.25, $source->getScore());
        $this->assertSame($firstScore, $first->getScore());
        $this->assertNotSame($source, $first);
    }

    public function test_custom_document_model(): void
    {
        $document = new Document('Hello World!');
        $document->addMetadata('customProperty', 'customValue');
        $document->setEmbedding([1, 0]);

        $store = new MemoryVectorStore();
        $store->addDocuments([$document]);

        $results = $store->search(new SearchRequest([1, 0]));

        $this->assertCount(1, $results);
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    public function test_delete_documents(): void
    {
        $document = new Document('Hello World!');
        $document->setEmbedding([1, 0]);

        $store = new MemoryVectorStore();
        $store->addDocuments([$document]);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $store->search(new SearchRequest([1, 0]));
        $this->assertCount(0, $results);
    }

    public function test_delete_by_type(): void
    {
        $document1 = new Document('Hello!');
        $document1->setEmbedding([1, 0]);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([0, 1]);
        $document2->setSourceType('web');
        $document2->setSourceName('page-b');

        $document3 = new Document('Hello 3!');
        $document3->setEmbedding([0.5, 0.5]);
        $document3->setSourceType('file');
        $document3->setSourceName('doc.txt');

        $store = new MemoryVectorStore();
        $store->addDocuments([$document1, $document2, $document3]);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $results = $store->search(new SearchRequest([1, 0]));
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
    }
}
