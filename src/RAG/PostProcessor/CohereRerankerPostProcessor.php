<?php

declare(strict_types=1);

namespace NeuronAI\RAG\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;

use function rtrim;
use function array_map;
use function array_values;

class CohereRerankerPostProcessor implements PostProcessorInterface
{
    use HasHttpClient;
    use MapsRerankResults;

    protected string $baseUri;

    public function __construct(
        protected string $key,
        protected string $model = 'rerank-v3.5',
        protected int $topN = 3,
        string $host = 'https://api.cohere.com/v2/',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->baseUri = $host;
        $this->httpHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->key,
        ];
    }

    /**
     * @throws HttpException
     * @throws ProviderException
     */
    public function process(Message $question, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        // The API names documents by their position in the list it received
        $documents = array_values($documents);

        $result = $this->httpClient->request(
            HttpRequest::post(
                uri: rtrim($this->baseUri, '/') . '/rerank',
                body: [
                    'model' => $this->model,
                    'query' => $question->getContent(),
                    'top_n' => $this->topN,
                    'documents' => array_map(fn (Document $document): string => $document->getContent(), $documents),
                ],
                headers: $this->httpHeaders,
            )
        )->json();

        return $this->rankedDocuments($documents, $result);
    }
}
