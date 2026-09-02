<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Compilers\MariaDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use PDO;
use function array_chunk;
use function in_array;
use function is_array;
use function json_decode;
use function json_encode;
use function sprintf;

class MariaDBVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    public function __construct(
        protected PDO $pdo,
        protected string $tableName = 'rag_documents',
        protected int $topK = 4,
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
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

    /**
     * @throws VectorStoreException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

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
                $stmt->execute([
                    ':id' => $document->getId(),
                    ':content' => $document->getContent(),
                    ':sourceType' => $document->getSourceType(),
                    ':sourceName' => $document->getSourceName(),
                    ':metadata' => json_encode($document->getMetadata()),
                    ':embedding' => json_encode($document->getEmbedding()),
                ]);
            }
        }

        return $this;
    }

    /**
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $compiled = (new MariaDBFilterCompiler($this->schema))->compile($filters);

        $stmt = $this->pdo->prepare(sprintf(
            'DELETE FROM %s WHERE %s',
            $this->tableName,
            $compiled['sql'],
        ));
        $stmt->execute($compiled['bindings']);

        return $this;
    }

    /**
     * @throws DocumentSchemaException
     */
    public function search(SearchRequest $request): iterable
    {
        $where = '';
        $bindings = [':embedding' => json_encode($request->embedding)];

        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
            $compiled = (new MariaDBFilterCompiler($this->schema))->compile($request->filters);
            $where = 'WHERE ' . $compiled['sql'];
            $bindings = [...$bindings, ...$compiled['bindings']];
        }

        $stmt = $this->pdo->prepare(sprintf(
            <<<'SQL'
                SELECT id, content, sourceType, sourceName, metadata,
                       VEC_DISTANCE_EUCLIDEAN(embedding, VEC_FromText(:embedding)) AS distance
                FROM %s
                %s
                ORDER BY distance ASC
                LIMIT %d
                SQL,
            $this->tableName,
            $where,
            $request->topK ?? $this->topK,
        ));

        $stmt->execute($bindings);

        $documents = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $document = new Document($row['content']);
            $document->setId($row['id'])
                ->setSourceType($row['sourceType'])
                ->setSourceName($row['sourceName'])
                ->setScore(VectorSimilarity::similarityFromDistance((float) $row['distance']));

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
