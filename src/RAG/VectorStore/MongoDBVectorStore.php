<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use Exception;
use MongoDB\Client;
use MongoDB\Collection;
use NeuronAI\RAG\Document;

use function array_chunk;
use function array_map;
use function in_array;
use function max;

class MongoDBVectorStore implements VectorStoreInterface
{
    protected Collection $collection;

    public function __construct(
        protected Client $client,
        protected string $database,
        protected string $collectionName = 'neuron_documents',
        protected int $topK = 4,
        protected string $vectorIndexName = 'vector_index',
    ) {
        $this->collection = $client->selectCollection($this->database, $this->collectionName);
    }

    public function setupVectorIndex(int $dimensions, string $similarity = 'cosine'): void
    {
        $this->collection->createSearchIndex(
            [
                'fields' => [[
                    'type' => 'vector',
                    'path' => 'embedding',
                    'numDimensions' => $dimensions,
                    'similarity' => $similarity,
                ]],
            ],
            [
                'name' => $this->vectorIndexName,
                'type' => 'vectorSearch',
            ],
        );
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
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        if ($documents[0]->embedding === []) {
            throw new Exception('Document embedding must be set before adding a document');
        }

        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $this->collection->insertMany(
                array_map(fn (Document $document): array => [
                    '_id' => (string) $document->getId(),
                    'embedding' => $document->getEmbedding(),
                    'content' => $document->getContent(),
                    'sourceType' => $document->getSourceType(),
                    'sourceName' => $document->getSourceName(),
                    'metadata' => (object) $document->metadata,
                ], $chunk)
            );
        }

        return $this;
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $filter = ['sourceType' => $sourceType];

        if ($sourceName !== null) {
            $filter['sourceName'] = $sourceName;
        }

        $this->collection->deleteMany($filter);

        return $this;
    }

    /**
     * Requires a MongoDB Atlas Vector Search index on the "embedding" field.
     * Create the index via Atlas UI or programmatically before calling this method.
     *
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $pipeline = [
            [
                '$vectorSearch' => [
                    'index' => $this->vectorIndexName,
                    'path' => 'embedding',
                    'queryVector' => $embedding,
                    'numCandidates' => max(100, $this->topK * 10),
                    'limit' => $this->topK,
                ],
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
            $document->sourceType = $item['sourceType'];
            $document->sourceName = $item['sourceName'];
            $document->score = (float) $item['score'];

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
