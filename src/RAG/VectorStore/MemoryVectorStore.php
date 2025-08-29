<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;

class MemoryVectorStore implements VectorStoreInterface
{
    /**
     * @var Document[]
     */
    private array $documents = [];

    public function __construct(protected int $topK = 4)
    {
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->documents[] = $document;
        return $this;
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->documents = \array_merge($this->documents, $documents);
        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->documents = \array_filter($this->documents, fn (Document $document): bool => $document->getSourceType() !== $sourceType || $document->getSourceName() !== $sourceName);
        return $this;
    }

    public function similaritySearch(array $embedding): array
    {
        $distances = [];

        foreach ($this->documents as $index => $document) {
            if ($document->embedding === []) {
                throw new VectorStoreException("Document with the following content has no embedding: {$document->getContent()}");
            }
            $dist = VectorSimilarity::cosineDistance($embedding, $document->getEmbedding());
            $distances[$index] = $dist;
        }

        \asort($distances); // Sort by distance (ascending).

        $topKIndices = \array_slice(\array_keys($distances), 0, $this->topK, true);

        return \array_reduce($topKIndices, function (array $carry, int $index) use ($distances): array {
            $document = $this->documents[$index];
            $document->setScore(VectorSimilarity::similarityFromDistance($distances[$index]));
            $carry[] = $document;
            return $carry;
        }, []);
    }
}
