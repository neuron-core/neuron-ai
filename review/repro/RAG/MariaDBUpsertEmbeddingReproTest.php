<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MariaDBVectorStore;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

use function preg_replace;
use function trim;

/**
 * VALUES(embedding) is the column value MariaDB would have inserted, i.e. the
 * binary VECTOR already produced by VEC_FromText(:embedding). Feeding it to
 * VEC_FromText again parses binary floats as JSON text, which yields NULL
 * (warning, escalated to an error in strict mode) on a NOT NULL column, so
 * re-adding a document with an existing id fails instead of updating it.
 */
class MariaDBUpsertEmbeddingReproTest extends TestCase
{
    public function test_duplicate_id_upsert_reuses_the_already_converted_embedding(): void
    {
        $preparedSql = [];

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$preparedSql): PDOStatement {
            $preparedSql[] = trim((string) preg_replace('/\s+/', ' ', $sql));
            $statement = $this->createMock(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            return $statement;
        });

        (new MariaDBVectorStore($pdo, 'rag_documents'))
            ->addDocument((new Document('One'))->setId('id-1')->setEmbedding([1, 0]));

        $this->assertSame([
            'INSERT INTO rag_documents (id, content, sourceType, sourceName, metadata, embedding) ' .
            'VALUES (:id, :content, :sourceType, :sourceName, :metadata, VEC_FromText(:embedding)) ' .
            'ON DUPLICATE KEY UPDATE content = VALUES(content), sourceType = VALUES(sourceType), ' .
            'sourceName = VALUES(sourceName), metadata = VALUES(metadata), embedding = VALUES(embedding)',
        ], $preparedSql);
    }
}
