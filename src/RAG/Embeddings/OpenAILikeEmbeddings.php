<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

class OpenAILikeEmbeddings extends OpenAIEmbeddingsProvider
{
    public function __construct(
        protected string $baseUri,
        protected string $key,
        protected string $model,
        protected int $dimensions = 1024
    ) {
        parent::__construct($key, $model, $dimensions);
    }
}
