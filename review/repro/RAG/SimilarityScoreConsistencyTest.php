<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\ChromaVectorStore;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function is_array;
use function preg_replace;
use function trim;

/**
 * Score thresholds are store-agnostic, so every store must report the same
 * metric: cosine similarity (1 - cosine distance), as the Memory store does.
 */
class SimilarityScoreConsistencyTest extends TestCase
{
    use RecordsVectorStoreRequests;

    /**
     * @var string[]
     */
    protected array $sentSql = [];

    protected function mariaDb(): MariaDBVectorStore
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);

        $pdo = $this->createMock(PDO::class);
        $record = function (string $sql): void {
            $this->sentSql[] = trim((string) preg_replace('/\s+/', ' ', $sql));
        };
        $pdo->method('exec')->willReturnCallback(function (string $sql) use ($record): int {
            $record($sql);
            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($record, $statement): PDOStatement {
            $record($sql);
            return $statement;
        });

        return new MariaDBVectorStore($pdo, 'rag_documents');
    }

    public function test_memory_store_reports_cosine_similarity(): void
    {
        $store = new MemoryVectorStore();
        $store->addDocument((new Document('doc'))->setEmbedding([0.8, 0.6]));

        $results = $store->search(new SearchRequest([1.0, 0.0]));
        $results = is_array($results) ? $results : iterator_to_array($results);

        $this->assertEqualsWithDelta(0.8, $results[0]->getScore(), 1e-9);
    }

    public function test_mariadb_indexes_and_ranks_by_cosine_distance(): void
    {
        $store = $this->mariaDb();
        $store->setupTable(2);
        $store->search(new SearchRequest([1.0, 0.0]));

        $this->assertStringContainsString('VECTOR INDEX (embedding) DISTANCE=cosine', $this->sentSql[0]);
        $this->assertStringContainsString('VEC_DISTANCE_COSINE(embedding, VEC_FromText(:embedding)) AS distance', $this->sentSql[1]);
    }

    public function test_chroma_creates_the_collection_in_cosine_space(): void
    {
        new ChromaVectorStore(
            collection: 'docs',
            host: 'http://chroma.test:8000/',
            httpClient: $this->recordingClient($this->jsonResponse(['id' => 'col-uuid'])),
        );

        $this->assertSame(['hnsw:space' => 'cosine'], $this->sentJson(0)['metadata'] ?? null);
    }
}
