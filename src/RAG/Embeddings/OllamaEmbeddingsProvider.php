<?php

declare(strict_types=1);

namespace NeuronAI\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

use function trim;

class OllamaEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    use HasHttpClient;

    public function __construct(
        protected string $model,
        protected string $url = 'http://localhost:11434/api',
        protected array $parameters = [],
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($this->url, '/').'/');
    }

    /**
     * @throws HttpException
     */
    public function embedText(string $text): array
    {
        $response = $this->httpClient->request(
            HttpRequest::post(
                uri: 'embed',
                body: [
                    'model' => $this->model,
                    'input' => $text,
                    ...$this->parameters,
                ]
            )
        )->json();

        return $response['embeddings'][0];
    }
}
