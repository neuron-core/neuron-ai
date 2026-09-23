<?php

declare(strict_types=1);

namespace NeuronAI\RAG\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;

use function rtrim;
use function array_map;
use function trim;

class LocalAIRerankerPostProcessor implements PostProcessorInterface
{
    use HasHttpClient;

    protected string $baseUri;

    public function __construct(
        protected string $key,
        protected string $model = 'cross-encoder',
        protected int    $topN = 3,
        string $host = 'http://localhost:8080/',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
        $this->baseUri = trim($host, '/').'/v1';
        $this->httpHeaders = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$this->key,
        ];
    }

    /**
     * @throws HttpException
     */
    public function process(Message $question, array $documents): array
    {
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

        return array_map(function (array $item) use ($documents): Document {
            $document = $documents[$item['index']];
            $document->setScore($item['relevance_score']);
            return $document;
        }, $result['results']);
    }
}
