<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter;

/**
 * A backend-native filter fragment, passed through verbatim by the one store
 * class it targets. Any other store refuses to compile it, so swapping stores
 * fails loudly instead of silently matching the wrong documents.
 *
 * Created via {@see Filter::raw()}.
 */
class RawFilter
{
    /**
     * @param class-string $store The vector store class this fragment is written for.
     * @param mixed $fragment Filter syntax in the target backend's native format.
     */
    public function __construct(
        public readonly string $store,
        public readonly mixed $fragment,
    ) {
    }
}
