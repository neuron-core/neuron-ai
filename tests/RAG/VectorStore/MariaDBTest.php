<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Support\CheckOpenPort;
use PDO;
use PHPUnit\Framework\TestCase;
use function uniqid;

class MariaDBTest extends TestCase
{
    use CheckOpenPort;

    protected PDO $pdo;
    protected string $tableName;
    protected MariaDBVectorStore $store;

    protected function setUp(): void
    {
        if (!$this->isPortOpen('127.0.0.1', 3307)) {
            $this->markTestSkipped('MariaDB not available on port 3307. Skipping test.');
        }

        $this->pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=neuron-ai', 'root', '');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->tableName = 'test_vectors_' . uniqid();
        $this->store = new MariaDBVectorStore($this->pdo, $this->tableName, topK: 4);
        $this->store->setupTable(dimensions: 3);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->tableName)) {
            $this->store->dropTable();
        }
    }

    public function test_mariadb_store_instance(): void
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

    public function test_search_returns_ordered_by_similarity(): void
    {
        $doc1 = new Document('Document 1');
        $doc1->setEmbedding([1, 0, 0]);

        $doc2 = new Document('Document 2');
        $doc2->setEmbedding([0, 1, 0]);

        $doc3 = new Document('Document 3');
        $doc3->setEmbedding([0.5, 0.5, 0]);

        $this->store->addDocuments([$doc1, $doc2, $doc3]);

        $results = $this->store->search(new SearchRequest([1, 0, 0]));

        $this->assertCount(3, $results);
        $this->assertGreaterThanOrEqual($results[1]->getScore(), $results[0]->getScore());
        $this->assertGreaterThanOrEqual($results[2]->getScore(), $results[1]->getScore());
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

    public function test_top_k_limits_results(): void
    {
        $store = new MariaDBVectorStore($this->pdo, $this->tableName . '_topk', topK: 2);
        $store->setupTable(dimensions: 3);

        $docs = [];
        for ($i = 0; $i < 5; $i++) {
            $doc = new Document("Document $i");
            $doc->setEmbedding([$i * 0.1, $i * 0.1, $i * 0.1]);
            $docs[] = $doc;
        }

        $store->addDocuments($docs);

        $results = $store->search(new SearchRequest([0, 0, 0]));
        $this->assertCount(2, $results);

        $store->dropTable();
    }
}
