<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\RAG\Document;

use function array_chunk;
use function array_map;
use function array_merge;
use function json_decode;

class VoyageEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    protected Client $client;

    protected string $baseUri = 'https://api.voyageai.com/v1/embeddings';

    public function __construct(
        string $key,
        protected string $model,
        protected ?int $dimensions = null
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
        ]);
    }

    public function embedText(string $text): array
    {
        $response = $this->client->post('', [
            RequestOptions::JSON => [
                'model' => $this->model,
                'input' => $text,
                'output_dimension' => $this->dimensions,
            ]
        ])->getBody()->getContents();

        $response = json_decode($response, true);

        return $response['data'][0]['embedding'];
    }

    public function embedDocuments(array $documents): array
    {
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $response = $this->client->post('', [
                RequestOptions::JSON => [
                    'model' => $this->model,
                    'input' => array_map(fn (Document $document): string => $document->getContent(), $chunk),
                    'output_dimension' => $this->dimensions,
                ]
            ])->getBody()->getContents();

            $response = json_decode($response, true);

            foreach ($response['data'] as $index => $item) {
                $chunk[$index]->embedding = $item['embedding'];
            }
        }

        return array_merge(...$chunks);
    }
}
