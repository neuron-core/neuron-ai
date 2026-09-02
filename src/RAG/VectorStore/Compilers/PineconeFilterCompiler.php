<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use NeuronAI\RAG\VectorStore\PineconeVectorStore;

/**
 * Compiles to a Pinecone metadata filter.
 *
 * https://docs.pinecone.io/guides/index-data/indexing-overview#metadata
 */
class PineconeFilterCompiler extends MongoStyleFilterCompiler
{
    protected const STORE = PineconeVectorStore::class;
}
