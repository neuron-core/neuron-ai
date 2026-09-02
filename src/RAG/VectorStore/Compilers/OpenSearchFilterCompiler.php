<?php

declare(strict_types=1);

namespace NeuronAI\RAG\VectorStore\Compilers;

use NeuronAI\RAG\VectorStore\OpenSearchVectorStore;

/**
 * OpenSearch shares the Elasticsearch query DSL; only the raw-filter target
 * differs, so a fragment written for one never leaks into the other.
 */
class OpenSearchFilterCompiler extends ElasticsearchFilterCompiler
{
    protected const STORE = OpenSearchVectorStore::class;
}
