<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Tests\Support\CheckOpenPort;
use PHPUnit\Framework\TestCase;
use function count;

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
        $document->setEmbedding([1, 2, 3]);

        $this->store->addDocument($document);

        $results = $this->store->search(new SearchRequest([1, 2, 3]));

        $this->assertCount(1, $results);
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    /**
     * @throws HttpException
     */
    public function test_add_multiple_document_and_search(): void
    {
        $document = new Document('Hello!');
        $document->addMetadata('customProperty', 'customValue');
        $document->setEmbedding([1, 2, 3]);

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([3, 4, 5]);

        $this->store->addDocuments([$document, $document2]);

        $results = $this->store->search(new SearchRequest([1, 2, 3]));

        $this->assertGreaterThanOrEqual(1, count($results));
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getMetadata()['customProperty'], $results[0]->getMetadata()['customProperty']);
    }

    /**
     * @throws HttpException
     */
    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->setEmbedding([1, 2, 3]);

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([3, 4, 5]);

        $this->store->addDocuments([$document, $document2]);
        $this->store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $this->store->search(new SearchRequest([1, 2, 3]));
        $this->assertCount(0, $results);
    }

    /**
     * @throws HttpException
     */
    public function test_delete_by_type(): void
    {
        $document1 = new Document('Hello!');
        $document1->setEmbedding([1, 2, 3]);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([3, 4, 5]);
        $document2->setSourceType('web');
        $document2->setSourceName('page-b');

        $document3 = new Document('Hello 3!');
        $document3->setEmbedding([2, 2, 2]);
        $document3->setSourceType('file');
        $document3->setSourceName('doc.txt');

        $this->store->addDocuments([$document1, $document2, $document3]);
        $this->store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $results = $this->store->search(new SearchRequest([1, 2, 3]));
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());
    }
}
