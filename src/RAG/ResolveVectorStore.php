<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

trait ResolveVectorStore
{
    protected VectorStoreInterface $store;

    public function setVectorStore(VectorStoreInterface $store): RAG
    {
        $this->store = $store;
        return $this;
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return new MemoryVectorStore();
    }

    public function resolveVectorStore(): VectorStoreInterface
    {
        return $this->store ?? $this->store = $this->vectorStore();
    }
}
