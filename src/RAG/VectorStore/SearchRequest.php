<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;

use function is_float;
use function is_int;

/**
 * Immutable, per-call search input. Nothing here outlives the call, so a
 * filter set for one search can never leak into the next.
 */
class SearchRequest
{
    /**
     * @param float[] $embedding
     * @param FilterExpression|null $filters Conditions the returned documents must match.
     * @param int|null $topK Result limit; null falls back to the store's default.
     * @throws VectorStoreException
     */
    public function __construct(
        public readonly array $embedding,
        public readonly ?FilterExpression $filters = null,
        public readonly ?int $topK = null,
    ) {
        if ($this->embedding === []) {
            throw new VectorStoreException('Search embedding cannot be empty.');
        }

        foreach ($this->embedding as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new VectorStoreException('Search embedding accepts numeric values only.');
            }
        }

        if ($this->topK !== null && $this->topK < 1) {
            throw new VectorStoreException('Search topK must be greater than zero.');
        }
    }
}
