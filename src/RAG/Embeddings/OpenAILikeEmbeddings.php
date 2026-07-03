<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\HttpClient\HttpClientInterface;

class OpenAILikeEmbeddings extends OpenAIEmbeddingsProvider
{
    public function __construct(
        string $baseUri,
        string $key,
        string $model,
        ?int $dimensions = 1024,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->baseUri = $baseUri;
        parent::__construct($key, $model, $dimensions, $httpClient);
    }
}
