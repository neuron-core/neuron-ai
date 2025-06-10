<?php

namespace NeuronAI\RAG\PostProcessor;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;

class JinaRerankerPostProcessor implements PostProcessorInterface
{
    protected Client $client;

    public function __construct(
        protected string $key,
        protected string $model = 'jina-reranker-v2-base-multilingual',
        protected int $topN = 3
    ) {
    }

    protected function getClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        return $this->client = new Client([
            'base_uri' => 'https://api.jina.ai/v1/',
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->key,
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
                'documents' => \array_map(fn (Document $document) => ['text' => $document->content], $documents),
                'return_documents' => false,
            ],
        ])->getBody()->getContents();

        $result = \json_decode($response, true);

        return \array_map(function ($item) use ($documents) {
            $document = $documents[$item['index']];
            $document->score = $item['relevance_score'];
            return $document;
        }, $result['results']);
    }

    public function setClient(Client $client): PostProcessorInterface
    {
        $this->client = $client;
        return $this;
    }
}
