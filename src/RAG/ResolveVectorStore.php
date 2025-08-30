<?php

declare(strict_types=1);

namespace NeuronAI\RAG;

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
        return $this->store;
    }

    public function resolveVectorStore(): VectorStoreInterface
    {
        if (!isset($this->store)) {
            $this->store = $this->vectorStore();
        }
        return $this->store;
    }
}
