<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use Exception;
use PDO;

use function array_chunk;
use function in_array;
use function json_decode;
use function json_encode;
use function sprintf;
use function is_array;

class MariaDBVectorStore implements VectorStoreInterface
{
    public function __construct(
        protected PDO $pdo,
        protected string $tableName = 'rag_documents',
        protected int $topK = 4,
    ) {
    }

    /**
     * Create the vector table. Requires MariaDB >=11.7.
     */
    public function setupTable(int $dimensions = 1536): void
    {
        $this->pdo->exec(sprintf(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS %s (
                    id UUID NOT NULL PRIMARY KEY,
                    content TEXT,
                    sourceType VARCHAR(255),
                    sourceName VARCHAR(255),
                    metadata JSON,
                    embedding VECTOR(%d) NOT NULL,
                    VECTOR INDEX (embedding)
                )
                SQL,
            $this->tableName,
            $dimensions,
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

        $stmt = $this->pdo->prepare(sprintf(
            <<<'SQL'
                INSERT INTO %s (id, content, sourceType, sourceName, metadata, embedding)
                VALUES (:id, :content, :sourceType, :sourceName, :metadata, VEC_FromText(:embedding))
                ON DUPLICATE KEY UPDATE
                    content = VALUES(content),
                    sourceType = VALUES(sourceType),
                    sourceName = VALUES(sourceName),
                    metadata = VALUES(metadata),
                    embedding = VEC_FromText(VALUES(embedding))
                SQL,
            $this->tableName,
        ));

        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            foreach ($chunk as $document) {
                if ($document->embedding === []) {
                    throw new Exception('Document embedding must be set before adding a document');
                }

                $stmt->execute([
                    ':id' => $document->getId(),
                    ':content' => $document->getContent(),
                    ':sourceType' => $document->getSourceType(),
                    ':sourceName' => $document->getSourceName(),
                    ':metadata' => json_encode($document->metadata),
                    ':embedding' => json_encode($document->getEmbedding()),
                ]);
            }
        }

        return $this;
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        if ($sourceName !== null) {
            $stmt = $this->pdo->prepare(sprintf(
                'DELETE FROM %s WHERE sourceType = :sourceType AND sourceName = :sourceName',
                $this->tableName,
            ));
            $stmt->execute([':sourceType' => $sourceType, ':sourceName' => $sourceName]);
        } else {
            $stmt = $this->pdo->prepare(sprintf(
                'DELETE FROM %s WHERE sourceType = :sourceType',
                $this->tableName,
            ));
            $stmt->execute([':sourceType' => $sourceType]);
        }

        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        $stmt = $this->pdo->prepare(sprintf(
            <<<'SQL'
                SELECT id, content, sourceType, sourceName, metadata,
                       VEC_DISTANCE_EUCLIDEAN(embedding, VEC_FromText(:embedding)) AS distance
                FROM %s
                ORDER BY distance ASC
                LIMIT %d
                SQL,
            $this->tableName,
            $this->topK,
        ));

        $stmt->execute([':embedding' => json_encode($embedding)]);

        $documents = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $document = new Document($row['content']);
            $document->id = $row['id'];
            $document->sourceType = $row['sourceType'];
            $document->sourceName = $row['sourceName'];
            $document->score = VectorSimilarity::similarityFromDistance((float) $row['distance']);

            $metadata = json_decode($row['metadata'] ?? '{}', true);
            if (is_array($metadata)) {
                foreach ($metadata as $key => $value) {
                    if (!in_array($key, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                        $document->addMetadata($key, $value);
                    }
                }
            }

            $documents[] = $document;
        }

        return $documents;
    }
}
