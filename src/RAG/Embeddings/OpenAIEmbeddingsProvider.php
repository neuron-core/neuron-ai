<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

use function trim;

class OpenAIEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    protected string $baseUri = 'https://api.openai.com/v1';

    public function __construct(
        protected string $key,
        protected string $model,
        protected ?int $dimensions = 1024,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->baseUri, '/').'/')
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
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'embeddings',
                body: [
                    'model' => $this->model,
                    'input' => $text,
                    'encoding_format' => 'float',
                    ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),
                ]
            )
        )->json();

        return $response['data'][0]['embedding'];
    }

    public function withoutDimensions(): self
    {
        $this->dimensions = null;
        return $this;
    }
}
