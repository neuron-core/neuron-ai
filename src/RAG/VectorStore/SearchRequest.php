<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

use NeuronAI\RAG\VectorStore\Filter\FilterGroup;

/**
 * Immutable, per-call search input. Nothing here outlives the call, so a
 * filter set for one search can never leak into the next.
 */
class SearchRequest
{
    /**
     * @param float[] $embedding
     * @param FilterGroup|null $filters Conditions the returned documents must match.
     * @param int|null $topK Result limit; null falls back to the store's default.
     */
    public function __construct(
        public readonly array $embedding,
        public readonly ?FilterGroup $filters = null,
        public readonly ?int $topK = null,
    ) {
    }
}
