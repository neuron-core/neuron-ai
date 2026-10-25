<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
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
     * @throws JsonException
     * @throws ProviderException
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

        if (!isset($response['embeddings'][0])) {
            throw new ProviderException('Ollama returned no embedding, as it does when the text to embed is empty.');
        }

        return $response['embeddings'][0];
    }
}
