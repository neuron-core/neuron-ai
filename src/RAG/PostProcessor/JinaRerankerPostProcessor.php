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

class JinaRerankerPostProcessor implements PostProcessorInterface
{
    use HasHttpClient;
    use MapsRerankResults;

    protected string $baseUri = 'https://api.jina.ai/v1';

    public function __construct(
        protected string $key,
        protected string $model = 'jina-reranker-v2-base-multilingual',
        protected int $topN = 3,
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
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
                    'documents' => array_map(fn (Document $document): array => ['text' => $document->getContent()], $documents),
                    'return_documents' => false,
                ],
                headers: $this->httpHeaders,
            )
        )->json();

        return $this->rankedDocuments($documents, $result);
    }
}
