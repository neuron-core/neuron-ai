<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore;

/**
 * todo: merge with the main interface in v4
 */
interface DeleteByInterface
{
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface;
}
