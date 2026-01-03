<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

class MistralEmbeddingsProvider extends OpenAIEmbeddingsProvider
{
    protected string $baseUri = 'https://api.mistral.ai/v1';
}
