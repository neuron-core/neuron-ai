<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use GuzzleHttp\Client;

use function json_decode;

class OpenAIEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    protected Client $client;

    protected string $baseUri = 'https://api.openai.com/v1/embeddings';

    public function __construct(
        protected string $key,
        protected string $model,
        protected ?int $dimensions = 1024
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

    public function embedDocuments(array $documents): array
    {
        $inputs = [];

        foreach ($documents as $document) {
            $text = $document->content;
            $inputs[] = $text;
        }

        $response = $this->client->post('', [
            'json' => [
                'model' => $this->model,
                'input' => $inputs,
                'encoding_format' => 'float',
                ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),

            ]
        ])->getBody()->getContents();

        $response = json_decode($response, true);

        foreach ($response['data'] as $key => $item) {
            $documents[$key]->embedding = $item['embedding'];
        }

        return $documents;
    }

    public function embedText(string $text): array
    {
        $response = $this->client->post('', [
            'json' => [
                'model' => $this->model,
                'input' => $text,
                'encoding_format' => 'float',
                ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),

            ]
        ])->getBody()->getContents();

        $response = json_decode($response, true);

        return $response['data'][0]['embedding'];
    }

    public function withoutDimensions(): self
    {
        $this->dimensions = null;
        return $this;
    }
}
