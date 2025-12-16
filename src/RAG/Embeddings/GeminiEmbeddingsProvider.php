<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

use function trim;

class GeminiEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    protected string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $config = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->key,
            ]);
    }

    /**
     * @throws HttpException
     */
    public function embedText(string $text): array
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: trim($this->baseUri, '/')."/{$this->model}:embedContent",
                body: [
                    'content' => [
                        'parts' => [['text' => $text]]
                    ],
                    ...$this->config,
                ]
            )
        )->json();

        return $response['embedding']['values'];
    }
}
