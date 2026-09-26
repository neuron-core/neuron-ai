<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function array_map;

class CohereRerankerPostProcessorTest extends TestCase
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
        $processor = new CohereRerankerPostProcessor(
            key: 'cohere-key',
            model: 'rerank-custom',
            topN: 2,
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('What is the capital of Italy?'), $this->capitals());

        $request = $this->sentRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.cohere.com/v2/rerank', (string) $request->getUri());
        $this->assertSame('Bearer cohere-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'model' => 'rerank-custom',
            'query' => 'What is the capital of Italy?',
            'top_n' => 2,
            'documents' => [
                'Paris is the capital of France',
                'Rome is the capital of Italy',
                'Madrid is the capital of Spain',
                'London is the capital of the United Kingdom',
            ],
        ], $this->sentJson());
    }

    public function test_defaults_to_rerank_v3_5_and_three_results(): void
    {
        $processor = new CohereRerankerPostProcessor(
            key: 'cohere-key',
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('Question'), [new Document('Context')]);

        $this->assertSame('rerank-v3.5', $this->sentJson()['model']);
        $this->assertSame(3, $this->sentJson()['top_n']);
    }

    #[TestWith(['https://proxy.example.com/v2'])]
    #[TestWith(['https://proxy.example.com/v2/'])]
    public function test_custom_host_is_joined_with_a_single_slash(string $host): void
    {
        $processor = new CohereRerankerPostProcessor(
            key: 'cohere-key',
            host: $host,
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('Question'), [new Document('Context')]);

        $this->assertSame('https://proxy.example.com/v2/rerank', (string) $this->sentRequest()->getUri());
    }

    public function test_documents_follow_the_api_ranking_with_its_relevance_scores(): void
    {
        $documents = $this->capitals();
        $documents[1]->addMetadata('country', 'Italy');
        $processor = new CohereRerankerPostProcessor(
            key: 'cohere-key',
            httpClient: $this->recordingClient($this->jsonResponse([
                'results' => [
                    ['index' => 1, 'relevance_score' => 0.9],
                    ['index' => 0, 'relevance_score' => 0.2],
                    ['index' => 3, 'relevance_score' => 0.0],
                ],
                'id' => '07734bd2-2473-4f07-94e1-0d9f0e6843cf',
            ])),
        );

        $result = $processor->process(new UserMessage('What is the capital of Italy?'), $documents);

        $this->assertSame([$documents[1], $documents[0], $documents[3]], $result);
        $this->assertSame([0.9, 0.2, 0.0], array_map(static fn (Document $document): ?float => $document->getScore(), $result));
        $this->assertSame(['country' => 'Italy'], $result[0]->getMetadata());
    }

    public function test_an_http_error_propagates_without_the_api_key(): void
    {
        $processor = new CohereRerankerPostProcessor(
            key: 'cohere-secret-key',
            httpClient: $this->recordingClient($this->jsonResponse(['message' => 'invalid api token'], 401)),
        );

        try {
            $processor->process(new UserMessage('Question'), [new Document('Context')]);
            $this->fail('An HTTP error must fail the rerank.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringContainsString('invalid api token', $exception->getMessage());
            $this->assertStringNotContainsString('cohere-secret-key', $exception->getMessage());
        }
    }
}
