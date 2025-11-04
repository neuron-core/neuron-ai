<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

class MistralEmbeddingsProvider extends OpenAIEmbeddingsProvider
{
    protected string $baseUri = 'https://api.mistral.ai/v1/embeddings';

    public function __construct(
        protected string $key,
        protected string $model = 'mistral-embed',
        protected ?int $dimensions = 1024
    ) {
        parent::__construct($key, $model, $dimensions);
    }
}
