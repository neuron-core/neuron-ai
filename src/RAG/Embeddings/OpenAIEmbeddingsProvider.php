<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use GuzzleHttp\Client;
use NeuronAI\RAG\Document;

use function json_decode;
use function array_chunk;
use function array_map;
use function array_merge;

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
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $response = $this->client->post('', [
                'json' => [
                    'model' => $this->model,
                    'input' => array_map(fn (Document $document): string => $document->getContent(), $chunk),
                    'encoding_format' => 'float',
                    ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),

                ]
            ])->getBody()->getContents();

            $response = json_decode($response, true);

            foreach ($response['data'] as $index => $item) {
                $chunk[$index]->embedding = $item['embedding'];
            }
        }

        return array_merge(...$chunks);
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
