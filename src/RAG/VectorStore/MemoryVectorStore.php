<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_reduce;
use function array_slice;
use function asort;
use function array_map;

class MemoryVectorStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    /**
     * @var Document[]
     */
    protected array $documents = [];

    public function __construct(protected int $topK = 4, ?DocumentSchema $schema = null)
    {
        $this->initializeSchema($schema);
    }

    /**
     * @throws VectorStoreException
     * @throws DocumentSchemaException
     */
    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->validateDocument($document);
        $this->documents[] = (clone $document)->setScore(null);
        return $this;
    }

    /**
     * @throws VectorStoreException
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        $storedDocuments = array_map(
            static fn (Document $document): Document => (clone $document)->setScore(null),
            $documents,
        );
        $this->documents = array_merge($this->documents, $storedDocuments);
        return $this;
    }

    /**
     * @throws DocumentSchemaException
     */
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
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

        if ($filters instanceof FilterExpression) {
            $this->validateFilters($filters);
        }

        foreach ($this->documents as $index => $document) {
            if ($filters instanceof FilterExpression && !$evaluator->matchesDocument($filters, $document)) {
                continue;
            }

            if ($document->getEmbedding() === null) {
                throw new VectorStoreException("Document with the following content has no embedding: {$document->getContent()}");
            }
            $dist = VectorSimilarity::cosineDistance($request->embedding, $document->getEmbedding());
            $distances[$index] = $dist;
        }

        asort($distances); // Sort by distance (ascending).

        $topKIndices = array_slice(array_keys($distances), 0, $request->topK ?? $this->topK, true);

        return array_reduce($topKIndices, function (array $carry, int $index) use ($distances): array {
            $document = clone $this->documents[$index];
            $document->setScore(VectorSimilarity::similarityFromDistance($distances[$index]));
            $carry[] = $document;
            return $carry;
        }, []);
    }
}
