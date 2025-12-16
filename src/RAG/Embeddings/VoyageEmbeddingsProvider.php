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

class VoyageEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.voyageai.com/v1/embeddings';

    public function __construct(
        string $key,
        protected string $model,
        protected ?int $dimensions = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri($this->baseUri)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ]);
    }

    /**
     * @throws HttpException
     */
    public function embedText(string $text): array
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: '',
                body: [
                    'model' => $this->model,
                    'input' => $text,
                    'output_dimension' => $this->dimensions,
                ]
            )
        )->json();

        return $response['data'][0]['embedding'];
    }

    /**
     * @throws HttpException
     */
    public function embedDocuments(array $documents): array
    {
        $chunks = array_chunk($documents, 100);

        foreach ($chunks as $chunk) {
            $response = $this->httpClient->request(
                HttpRequest::post(
                    uri: '',
                    body: [
                        'model' => $this->model,
                        'input' => array_map(fn (Document $document): string => $document->getContent(), $chunk),
                        'output_dimension' => $this->dimensions,
                    ]
                )
            )->json();

            foreach ($response['data'] as $index => $item) {
                $chunk[$index]->embedding = $item['embedding'];
            }
        }

        return array_merge(...$chunks);
    }
}
