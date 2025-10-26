<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use GuzzleHttp\Client;

class OpenAIEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    protected Client $client;

    protected string $baseUri = 'https://api.openai.com/v1/embeddings';

    protected array $modelWithoutDimensions = [
        'text-embedding-ada-002',
    ];

    public function __construct(
        protected string $key,
        protected string $model,
        protected int $dimensions = 1024
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]
        ]);
    }

    public function embedText(string $text): array
    {
        $response = $this->client->post('', [
            'json' => [
                'model' => $this->model,
                'input' => $text,
                'encoding_format' => 'float',
                ...($this->modelNeedsDimensions() ? ['dimensions' => $this->dimensions] : []),

            ]
        ])->getBody()->getContents();

        $response = \json_decode($response, true);

        return $response['data'][0]['embedding'];
    }

    protected function modelNeedsDimensions(): bool
    {
        return !in_array($this->model, $this->modelWithoutDimensions);
    }
}
