<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Exception\SearchNotSupportedException;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Compilers\MongoDBFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use function array_chunk;
use function array_map;
use function in_array;
use function max;

class MongoDBVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    protected Collection $collection;

    public function __construct(
        protected Client $client,
        protected string $database,
        protected string $collectionName = 'neuron_documents',
        protected int $topK = 4,
        protected string $vectorIndexName = 'vector_index',
        ?DocumentSchema $schema = null,
    ) {
        $this->initializeSchema($schema);
        $this->collection = $client->selectCollection($this->database, $this->collectionName);
    }

    public function setupVectorIndex(int $dimensions, string $similarity = 'cosine'): void
    {
        $fields = [[
            'type' => 'vector',
            'path' => 'embedding',
            'numDimensions' => $dimensions,
            'similarity' => $similarity,
        ]];

        foreach ($this->schema->fields() as $field) {
            if ($field->isFilterable()) {
                $fields[] = [
                    'type' => 'filter',
                    'path' => 'metadata.'.$field->getName(),
                ];
            }
        }

        $fields[] = ['type' => 'filter', 'path' => 'sourceType'];
        $fields[] = ['type' => 'filter', 'path' => 'sourceName'];

        try {
            $this->collection->createSearchIndex(
                [
                    'fields' => $fields,
                ],
                [
                    'name' => $this->vectorIndexName,
                    'type' => 'vectorSearch',
                ],
            );
        } catch (SearchNotSupportedException) {
            // Atlas Search not available — index must be created manually via Atlas UI
        }
    }

    public function dropCollection(): void
    {
        $this->collection->drop();
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param Document[] $documents
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->validateDocuments($documents);

        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $this->collection->insertMany(
                array_map(fn (Document $document): array => [
                    '_id' => (string) $document->getId(),
                    'embedding' => $document->getEmbedding(),
                    'content' => $document->getContent(),
                    'sourceType' => $document->getSourceType(),
                    'sourceName' => $document->getSourceName(),
                    'metadata' => (object) $document->getMetadata(),
                ], $chunk)
            );
        }

        return $this;
    }

    /**
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $this->collection->deleteMany((new MongoDBFilterCompiler())->compile($filters));

        return $this;
    }

    /**
     * Requires a MongoDB Atlas Vector Search index on the "embedding" field.
     * Create the index via Atlas UI or programmatically before calling this
     * method. Filtered fields must be declared in the index as filter fields.
     *
     * @return Document[]
     * @throws DocumentSchemaException
     */
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $topK = $request->topK ?? $this->topK;

        $vectorSearch = [
            'index' => $this->vectorIndexName,
            'path' => 'embedding',
            'queryVector' => $request->embedding,
            'numCandidates' => max(100, $topK * 10),
            'limit' => $topK,
        ];

        if ($request->filters instanceof FilterExpression) {
            $vectorSearch['filter'] = (new MongoDBFilterCompiler())->compile($request->filters);
        }

        $pipeline = [
            [
                '$vectorSearch' => $vectorSearch,
            ],
            [
                '$project' => [
                    '_id' => 0,
                    'content' => 1,
                    'sourceType' => 1,
                    'sourceName' => 1,
                    'metadata' => 1,
                    'score' => ['$meta' => 'vectorSearchScore'],
                ],
            ],
        ];

        $results = $this->collection->aggregate(
            $pipeline,
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        )->toArray();

        return array_map(function (array $item): Document {
            $document = new Document($item['content']);
            $document->setSourceType($item['sourceType'])
                ->setSourceName($item['sourceName'])
                ->setScore((float) $item['score']);

            $metadata = (array) ($item['metadata'] ?? []);
            foreach ($metadata as $key => $value) {
                if (!in_array($key, ['content', 'sourceType', 'sourceName', 'score', 'embedding', 'id'])) {
                    $document->addMetadata($key, (string) $value);
                }
            }

            return $document;
        }, $results);
    }
}
