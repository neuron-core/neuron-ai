<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_reduce;
use function array_slice;
use function asort;

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
        $this->documents = array_merge($this->documents, $documents);
        return $this;
    }

    public function delete(FilterGroup $filters): VectorStoreInterface
    {
        $evaluator = new FilterEvaluator();

        $this->documents = array_filter(
            $this->documents,
            fn (Document $document): bool => !$evaluator->matchesDocument($filters, $document)
        );
        return $this;
    }

    /**
     * @throws VectorStoreException
     */
    public function search(SearchRequest $request): array
    {
        $distances = [];
        $filters = $request->filters;
        $evaluator = new FilterEvaluator();

        foreach ($this->documents as $index => $document) {
            if ($filters instanceof FilterGroup && !$evaluator->matchesDocument($filters, $document)) {
                continue;
            }

            if ($document->embedding === []) {
                throw new VectorStoreException("Document with the following content has no embedding: {$document->getContent()}");
            }
            $dist = VectorSimilarity::cosineDistance($request->embedding, $document->getEmbedding());
            $distances[$index] = $dist;
        }

        asort($distances); // Sort by distance (ascending).

        $topKIndices = array_slice(array_keys($distances), 0, $request->topK ?? $this->topK, true);

        return array_reduce($topKIndices, function (array $carry, int $index) use ($distances): array {
            $document = $this->documents[$index];
            $document->setScore(VectorSimilarity::similarityFromDistance($distances[$index]));
            $carry[] = $document;
            return $carry;
        }, []);
    }
}
