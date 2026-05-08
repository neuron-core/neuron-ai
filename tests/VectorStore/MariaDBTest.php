<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Tests\Traits\CheckOpenPort;
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

        $this->pdo = new PDO('mysql:host=127.0.0.1;port=3307', 'root', '');
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

    public function test_search_returns_ordered_by_similarity(): void
    {
        $doc1 = new Document('Document 1');
        $doc1->embedding = [1, 0, 0];

        $doc2 = new Document('Document 2');
        $doc2->embedding = [0, 1, 0];

        $doc3 = new Document('Document 3');
        $doc3->embedding = [0.5, 0.5, 0];

        $this->store->addDocuments([$doc1, $doc2, $doc3]);

        $results = $this->store->similaritySearch([1, 0, 0]);

        $this->assertCount(3, $results);
        $this->assertGreaterThanOrEqual($results[1]->getScore(), $results[0]->getScore());
        $this->assertGreaterThanOrEqual($results[2]->getScore(), $results[1]->getScore());
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
        $this->store->deleteBySource('manual', 'manual');

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

    public function test_top_k_limits_results(): void
    {
        $store = new MariaDBVectorStore($this->pdo, $this->tableName . '_topk', topK: 2);
        $store->setupTable(dimensions: 3);

        $docs = [];
        for ($i = 0; $i < 5; $i++) {
            $doc = new Document("Document $i");
            $doc->embedding = [$i * 0.1, $i * 0.1, $i * 0.1];
            $docs[] = $doc;
        }

        $store->addDocuments($docs);

        $results = $store->similaritySearch([0, 0, 0]);
        $this->assertCount(2, $results);

        $store->dropTable();
    }
}
