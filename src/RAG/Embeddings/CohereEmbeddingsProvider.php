<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;

use function array_chunk;
use function array_map;
use function array_merge;

class CohereEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.cohere.com/v2';

    public function __construct(
        protected string $key,
        protected string $model,
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ]);
    }

    /**
     * @throws HttpException
     */
    public function embedText(string $text): array
    {
        $response = $this->httpClient->request(HttpRequest::post('embed', [
            'model' => $this->model,
            'texts' => [$text],
            'embedding_types' => ['float'],
            ...array_merge([
                'input_type' => 'search_query',
            ], $this->parameters),
        ]))->json();

        return $response['embeddings']['float'][0];
    }

    /**
     * @throws HttpException
     */
    public function embedDocuments(array $documents): array
    {
        $chunks = array_chunk($documents, 96);

        foreach ($chunks as $chunk) {
            $response = $this->httpClient->request(HttpRequest::post('embed', [
                'model' => $this->model,
                'texts' => array_map(fn (Document $document): string => $document->getContent(), $chunk),
                'embedding_types' => ['float'],
                ...array_merge([
                    'input_type' => 'search_query',
                ], $this->parameters),
            ]))->json();

            foreach ($response['embeddings']['float'] as $index => $item) {
                $chunk[$index]->embedding = $item;
            }
        }

        return array_merge(...$chunks);
    }
}
