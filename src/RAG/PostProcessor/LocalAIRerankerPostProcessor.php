<?php

declare(strict_types=1);

namespace NeuronAI\RAG\PostProcessor;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HasHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;

use function array_map;
use function trim;

class LocalAIRerankerPostProcessor implements PostProcessorInterface
{
    use HasHttpClient;

    public function __construct(
        protected string $key,
        protected string $model = 'cross-encoder',
        protected int    $topN = 3,
        protected string $host = 'http://localhost:8080/v1/',
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = ($httpClient ?? new GuzzleHttpClient())
            ->withBaseUri(trim($host, '/').'/')
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->key,
            ]);
    }

    /**
     * @throws HttpException
     */
    public function process(Message $question, array $documents): array
    {
        $result = $this->httpClient->request(
            HttpRequest::post(
                uri: 'rerank',
                body: [
                    'model' => $this->model,
                    'query' => $question->getContent(),
                    'top_n' => $this->topN,
                    'documents' => array_map(fn (Document $document): string => $document->getContent(), $documents),
                ]
            )
        )->json();

        return array_map(function (array $item) use ($documents): Document {
            $document = $documents[$item['index']];
            $document->setScore($item['relevance_score']);
            return $document;
        }, $result['results']);
    }
}
