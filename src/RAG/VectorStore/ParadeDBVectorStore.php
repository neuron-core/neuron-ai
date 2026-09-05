<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use InvalidArgumentException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use PDO;
use PDOStatement;

use function array_chunk;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function preg_match;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

class ParadeDBVectorStore implements HybridVectorStoreInterface
{
    protected string $tableName;

    protected string $bm25IndexName;

    protected string $vectorIndexName;

    public function __construct(
        protected PDO $pdo,
        string $tableName = 'rag_documents',
        protected int $topK = 4,
        protected int $rrfK = 60,
    ) {
        $this->tableName = $this->validateIdentifier($tableName);
        $this->bm25IndexName = $this->validateIdentifier($tableName.'_bm25_idx');
        $this->vectorIndexName = $this->validateIdentifier($tableName.'_embedding_idx');
    }

    public function setupTable(int $dimensions = 1536): void
    {
        if ($dimensions < 1) {
            throw new InvalidArgumentException('Embedding dimensions must be greater than zero.');
        }

        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        $this->pdo->exec('CREATE EXTENSION IF NOT EXISTS pg_search');
        $this->pdo->exec(sprintf(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS %s (
                    id TEXT PRIMARY KEY,
                    content TEXT NOT NULL,
                    source_type VARCHAR(255) NOT NULL,
                    source_name VARCHAR(255) NOT NULL,
                    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
                    embedding VECTOR(%d) NOT NULL
                )
                SQL,
            $this->tableName,
            $dimensions,
        ));
        $this->pdo->exec(sprintf(
            "CREATE INDEX IF NOT EXISTS %s ON %s USING bm25 (id, content) WITH (key_field = 'id')",
            $this->bm25IndexName,
            $this->tableName,
        ));
        $this->pdo->exec(sprintf(
            'CREATE INDEX IF NOT EXISTS %s ON %s USING hnsw (embedding vector_cosine_ops)',
            $this->vectorIndexName,
            $this->tableName,
        ));
    }

    public function dropTable(): void
    {
        $this->pdo->exec(sprintf('DROP TABLE IF EXISTS %s', $this->tableName));
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $statement = $this->pdo->prepare(sprintf(
            <<<'SQL'
                INSERT INTO %s (id, content, source_type, source_name, metadata, embedding)
                VALUES (:id, :content, :source_type, :source_name, CAST(:metadata AS jsonb), CAST(:embedding AS vector))
                ON CONFLICT (id) DO UPDATE SET
                    content = EXCLUDED.content,
                    source_type = EXCLUDED.source_type,
                    source_name = EXCLUDED.source_name,
                    metadata = EXCLUDED.metadata,
                    embedding = EXCLUDED.embedding
                SQL,
            $this->tableName,
        ));

        foreach (array_chunk($documents, 100) as $chunk) {
            foreach ($chunk as $document) {
                if ($document->getEmbedding() === []) {
                    throw new VectorStoreException('Document embedding must be set before adding a document.');
                }

                $statement->execute([
                    ':id' => (string) $document->getId(),
                    ':content' => $document->getContent(),
                    ':source_type' => $document->getSourceType(),
                    ':source_name' => $document->getSourceName(),
                    ':metadata' => json_encode($document->metadata, JSON_THROW_ON_ERROR),
                    ':embedding' => $this->encodeEmbedding($document->getEmbedding()),
                ]);
            }
        }

        return $this;
    }

    /**
     * @deprecated Use deleteBy() instead.
     */
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $sql = sprintf('DELETE FROM %s WHERE source_type = :source_type', $this->tableName);
        $parameters = [':source_type' => $sourceType];

        if ($sourceName !== null) {
            $sql .= ' AND source_name = :source_name';
            $parameters[':source_name'] = $sourceName;
        }

        $this->pdo->prepare($sql)->execute($parameters);
        return $this;
    }

    public function similaritySearch(array $embedding): array
    {
        $statement = $this->pdo->prepare(sprintf(
            <<<'SQL'
                SELECT id, content, source_type, source_name, metadata,
                       embedding <=> CAST(:embedding AS vector) AS distance
                FROM %s
                ORDER BY embedding <=> CAST(:embedding AS vector)
                LIMIT %d
                SQL,
            $this->tableName,
            $this->topK,
        ));
        $statement->execute([':embedding' => $this->encodeEmbedding($embedding)]);

        return $this->hydrateDocuments($statement, true);
    }

    public function hybridSearch(string $query, array $embedding): array
    {
        $candidateLimit = $this->topK * 2;
        $statement = $this->pdo->prepare(sprintf(
            <<<'SQL'
                WITH vector_results AS (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY embedding <=> CAST(:embedding AS vector)) AS rank
                    FROM %1$s
                    ORDER BY embedding <=> CAST(:embedding AS vector)
                    LIMIT %2$d
                ),
                bm25_results AS (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY pdb.score(id) DESC) AS rank
                    FROM %1$s
                    WHERE content ||| :query
                    ORDER BY pdb.score(id) DESC
                    LIMIT %2$d
                ),
                fused AS (
                    SELECT id, SUM(score) AS score
                    FROM (
                        SELECT id, 1.0 / (%3$d + rank) AS score FROM vector_results
                        UNION ALL
                        SELECT id, 1.0 / (%3$d + rank) AS score FROM bm25_results
                    ) rankings
                    GROUP BY id
                )
                SELECT documents.id, documents.content, documents.source_type,
                       documents.source_name, documents.metadata, fused.score
                FROM fused
                JOIN %1$s documents ON documents.id = fused.id
                ORDER BY fused.score DESC, documents.id
                LIMIT %4$d
                SQL,
            $this->tableName,
            $candidateLimit,
            $this->rrfK,
            $this->topK,
        ));
        $statement->execute([
            ':embedding' => $this->encodeEmbedding($embedding),
            ':query' => $query,
        ]);

        return $this->hydrateDocuments($statement);
    }

    /**
     * @return Document[]
     */
    protected function hydrateDocuments(PDOStatement $statement, bool $distanceScore = false): array
    {
        $documents = [];

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $document = new Document((string) $row['content']);
            $document->id = (string) $row['id'];
            $document->sourceType = (string) $row['source_type'];
            $document->sourceName = (string) $row['source_name'];
            $document->score = $distanceScore
                ? VectorSimilarity::similarityFromDistance((float) $row['distance'])
                : (float) $row['score'];

            $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
            $document->metadata = is_array($metadata) ? $metadata : [];
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param float[] $embedding
     */
    protected function encodeEmbedding(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }

    protected function validateIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier) !== 1 || strlen($identifier) > 63) {
            throw new InvalidArgumentException("Invalid PostgreSQL identifier: {$identifier}");
        }

        return $identifier;
    }
}
