<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\Filter\RawFilter;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

abstract class FilterCompiler
{
    protected const STORE = VectorStoreInterface::class;

    /**
     * A raw fragment is written in one backend's native syntax: compiling it
     * for any other store must fail loudly, never silently misfilter.
     */
    protected function assertTargetsThisStore(RawFilter $raw): void
    {
        if ($raw->store !== static::STORE) {
            throw new VectorStoreException(
                "Raw filter targets {$raw->store}; it cannot be compiled for " . static::STORE . '.'
            );
        }
    }
}
