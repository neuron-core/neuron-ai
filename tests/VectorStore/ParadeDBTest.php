<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ParadeDBVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PDO;
use PHPUnit\Framework\TestCase;

use function getenv;
use function uniqid;

class ParadeDBTest extends TestCase
{
    protected PDO $pdo;
    protected ParadeDBVectorStore $store;
    protected string $tableName;

    protected function setUp(): void
    {
        $dsn = getenv('PARADEDB_DSN');
        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('PARADEDB_DSN is not configured. Skipping ParadeDB integration tests.');
        }

        $this->pdo = new PDO(
            $dsn,
            getenv('PARADEDB_USER') ?: 'postgres',
            getenv('PARADEDB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        $this->tableName = 'test_paradedb_'.uniqid();
        $this->store = new ParadeDBVectorStore($this->pdo, $this->tableName, topK: 2);
        $this->store->setupTable(dimensions: 3);
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) {
            $this->store->dropTable();
        }
    }

    public function test_store_setup_is_idempotent(): void
    {
        $this->store->setupTable(dimensions: 3);
        $this->assertInstanceOf(VectorStoreInterface::class, $this->store);
    }

    public function test_add_search_and_upsert_documents(): void
    {
        $first = $this->document('Semantic result', [1, 0, 0], 'web', 'first');
        $first->addMetadata('language', 'en');
        $second = $this->document('Other result', [0, 1, 0], 'file', 'second');
        $this->store->addDocuments([$first, $second]);
        $first->content = 'Updated semantic result';
        $this->store->addDocument($first);

        $results = $this->store->similaritySearch([1, 0, 0]);

        $this->assertCount(2, $results);
        $this->assertSame($first->getId(), $results[0]->getId());
        $this->assertSame('Updated semantic result', $results[0]->getContent());
        $this->assertSame('web', $results[0]->getSourceType());
        $this->assertSame('first', $results[0]->getSourceName());
        $this->assertSame('en', $results[0]->metadata['language']);
        $this->assertGreaterThanOrEqual($results[1]->getScore(), $results[0]->getScore());
    }

    public function test_similarity_search_respects_top_k(): void
    {
        $this->store->addDocuments([
            $this->document('First', [1, 0, 0]),
            $this->document('Second', [0.9, 0.1, 0]),
            $this->document('Third', [0, 1, 0]),
        ]);

        $this->assertCount(2, $this->store->similaritySearch([1, 0, 0]));
    }

    public function test_hybrid_search_combines_bm25_and_vector_rankings(): void
    {
        $lexical = $this->document('ZXQ-991 exact product code', [0, 1, 0]);
        $semantic = $this->document('A conceptually similar description', [1, 0, 0]);
        $other = $this->document('Unrelated material', [0, 0, 1]);
        $this->store->addDocuments([$lexical, $semantic, $other]);

        $results = $this->store->hybridSearch('ZXQ-991', [1, 0, 0]);

        $this->assertCount(2, $results);
        $this->assertSame($lexical->getId(), $results[0]->getId());
        $this->assertGreaterThanOrEqual($results[1]->getScore(), $results[0]->getScore());
    }

    public function test_hybrid_search_falls_back_to_vector_results(): void
    {
        $semantic = $this->document('Semantic match', [1, 0, 0]);
        $this->store->addDocument($semantic);

        $results = $this->store->hybridSearch('terms absent from corpus', [1, 0, 0]);

        $this->assertCount(1, $results);
        $this->assertSame($semantic->getId(), $results[0]->getId());
    }

    public function test_delete_by_source_type_and_name(): void
    {
        $this->store->addDocuments([
            $this->document('Web A', [1, 0, 0], 'web', 'a'),
            $this->document('Web B', [0, 1, 0], 'web', 'b'),
            $this->document('File', [0, 0, 1], 'file', 'c'),
        ]);
        $this->store->deleteBy('web', 'a');
        $this->assertCount(2, $this->store->similaritySearch([1, 0, 0]));

        $this->store->deleteBy('web');
        $results = $this->store->similaritySearch([1, 0, 0]);
        $this->assertCount(1, $results);
        $this->assertSame('file', $results[0]->getSourceType());
    }

    public function test_document_without_embedding_is_rejected(): void
    {
        $this->expectException(VectorStoreException::class);
        $this->store->addDocument(new Document('Missing embedding'));
    }

    /** @param float[] $embedding */
    protected function document(string $content, array $embedding, string $sourceType = 'manual', string $sourceName = 'manual'): Document
    {
        $document = new Document($content);
        $document->embedding = $embedding;
        $document->sourceType = $sourceType;
        $document->sourceName = $sourceName;
        return $document;
    }
}
