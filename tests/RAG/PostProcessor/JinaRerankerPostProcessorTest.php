<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\TestCase;

use function array_map;

class JinaRerankerPostProcessorTest extends TestCase
{
    use RecordsJsonRequests;

    /** @return Document[] */
    protected function capitals(): array
    {
        return [
            new Document('Paris is the capital of France'),
            new Document('Rome is the capital of Italy'),
            new Document('Madrid is the capital of Spain'),
            new Document('London is the capital of the United Kingdom'),
        ];
    }

    public function test_rerank_request_carries_query_documents_model_and_top_n(): void
    {
        $processor = new JinaRerankerPostProcessor(
            key: 'jina-key',
            model: 'jina-reranker-custom',
            topN: 2,
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('What is the capital of Italy?'), $this->capitals());

        $request = $this->sentRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.jina.ai/v1/rerank', (string) $request->getUri());
        $this->assertSame('Bearer jina-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'model' => 'jina-reranker-custom',
            'query' => 'What is the capital of Italy?',
            'top_n' => 2,
            'documents' => [
                ['text' => 'Paris is the capital of France'],
                ['text' => 'Rome is the capital of Italy'],
                ['text' => 'Madrid is the capital of Spain'],
                ['text' => 'London is the capital of the United Kingdom'],
            ],
            'return_documents' => false,
        ], $this->sentJson());
    }

    public function test_defaults_to_the_multilingual_model_and_three_results(): void
    {
        $processor = new JinaRerankerPostProcessor(
            key: 'jina-key',
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('Question'), [new Document('Context')]);

        $this->assertSame('jina-reranker-v2-base-multilingual', $this->sentJson()['model']);
        $this->assertSame(3, $this->sentJson()['top_n']);
    }

    public function test_documents_follow_the_api_ranking_with_its_relevance_scores(): void
    {
        $documents = $this->capitals();
        $processor = new JinaRerankerPostProcessor(
            key: 'jina-key',
            httpClient: $this->recordingClient($this->jsonResponse([
                'results' => [
                    ['index' => 1, 'relevance_score' => 0.9],
                    ['index' => 0, 'relevance_score' => 0.2],
                    ['index' => 2, 'relevance_score' => 0.1],
                ],
            ])),
        );

        $result = $processor->process(new UserMessage('What is the capital of Italy?'), $documents);

        $this->assertSame([$documents[1], $documents[0], $documents[2]], $result);
        $this->assertSame([0.9, 0.2, 0.1], array_map(static fn (Document $document): ?float => $document->getScore(), $result));
    }

    public function test_an_http_error_propagates_without_the_api_key(): void
    {
        $processor = new JinaRerankerPostProcessor(
            key: 'jina-secret-key',
            httpClient: $this->recordingClient($this->jsonResponse(['detail' => 'Unauthorized'], 401)),
        );

        try {
            $processor->process(new UserMessage('Question'), [new Document('Context')]);
            $this->fail('An HTTP error must fail the rerank.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringNotContainsString('jina-secret-key', $exception->getMessage());
        }
    }
}
