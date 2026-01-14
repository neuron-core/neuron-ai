<?php

namespace NeuronAI\RAG\PostProcessor;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

class LocalAIRerankerPostProcessor implements PostProcessorInterface
{
    protected Client $client;

    public function __construct(
        protected string $key,
        protected string $model = 'cross-encoder',
        protected int    $topN = 3,
        protected string $host = 'http://localhost:8080/v1/'
    )
    {
    }

    protected function getClient(): Client
    {

        return $this->client ?? $this->client = new Client([
            'base_uri' => $this->host,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ],
        ]);
    }

    public function process(Message $question, array $documents): array
    {
        $response = $this->getClient()->post('rerank', [
            RequestOptions::JSON => [
                'model' => $this->model,
                'query' => $question->getContent(),
                'top_n' => $this->topN,
                'documents' => \array_map(function (Document $document) {return $document->getContent();}, $documents),
            ],
        ])->getBody()->getContents();

        $result = \json_decode($response, true);

        return \array_map(function (array $item) use ($documents): Document {
            $document = $documents[$item['index']];
            $document->setScore($item['relevance_score']);
            return $document;
        }, $result['results']);
    }

    public function setClient(Client $client): PostProcessorInterface
    {
        $this->client = $client;
        return $this;
    }

}
