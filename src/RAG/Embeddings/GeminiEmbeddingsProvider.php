<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class GeminiEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    protected Client $client;

    protected string $baseUri = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $config = []
    ) {
        $this->client = new Client([
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->key,
            ]
        ]);
    }

    public function embedText(string $text): array
    {
        $response = $this->client->post(\trim($this->baseUri, '/')."/{$this->model}:embedContent", [
            RequestOptions::JSON => [
                'content' => [
                    'parts' => [['text' => $text]]
                ],
                ...$this->config,
            ]
        ])->getBody()->getContents();

        $response = \json_decode($response, true);

        return $response['embedding']['values'];
    }
}
