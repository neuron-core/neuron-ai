<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Filter\Compilers;

use NeuronAI\RAG\VectorStore\ChromaVectorStore;

/**
 * Compiles to a ChromaDB "where" filter.
 *
 * https://docs.trychroma.com/docs/querying-collections/metadata-filtering
 */
class ChromaFilterCompiler extends MongoStyleFilterCompiler
{
    protected const STORE = ChromaVectorStore::class;
}
