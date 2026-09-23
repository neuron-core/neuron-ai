<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

use function rtrim;

class OllamaEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    protected string $baseUri;

    public function __construct(
        protected string $model,
        string $url = 'http://localhost:11434/api',
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->baseUri = $url;
    }

    /**
     * @throws HttpException
     */
    public function embedText(string $text): array
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: rtrim($this->baseUri, '/') . '/embed',
                body: [
                    'model' => $this->model,
                    'input' => $text,
                    ...$this->parameters,
                ],
                headers: $this->httpHeaders,
            )
        )->json();

        return $response['embeddings'][0];
    }
}
