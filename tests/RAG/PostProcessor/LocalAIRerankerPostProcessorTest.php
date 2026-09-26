<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function array_map;

class LocalAIRerankerPostProcessorTest extends TestCase
{
    use RecordsJsonRequests;

    public function test_rerank_request_targets_the_local_server_with_defaults(): void
    {
        $processor = new LocalAIRerankerPostProcessor(
            key: 'local-key',
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('Question'), [new Document('First'), new Document('Second')]);

        $request = $this->sentRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('http://localhost:8080/v1/rerank', (string) $request->getUri());
        $this->assertSame('Bearer local-key', $request->getHeaderLine('Authorization'));
        $this->assertSame([
            'model' => 'cross-encoder',
            'query' => 'Question',
            'top_n' => 3,
            'documents' => ['First', 'Second'],
        ], $this->sentJson());
    }

    #[TestWith(['http://reranker:9000'])]
    #[TestWith(['http://reranker:9000/'])]
    public function test_custom_host_gets_the_v1_prefix_once(string $host): void
    {
        $processor = new LocalAIRerankerPostProcessor(
            key: 'local-key',
            model: 'bge-reranker',
            topN: 1,
            host: $host,
            httpClient: $this->recordingClient($this->jsonResponse(['results' => []])),
        );

        $processor->process(new UserMessage('Question'), [new Document('Context')]);

        $this->assertSame('http://reranker:9000/v1/rerank', (string) $this->sentRequest()->getUri());
        $this->assertSame('bge-reranker', $this->sentJson()['model']);
        $this->assertSame(1, $this->sentJson()['top_n']);
    }

    public function test_documents_follow_the_api_ranking_with_its_relevance_scores(): void
    {
        $documents = [new Document('First'), new Document('Second'), new Document('Third')];
        $processor = new LocalAIRerankerPostProcessor(
            key: 'local-key',
            httpClient: $this->recordingClient($this->jsonResponse([
                'results' => [
                    ['index' => 2, 'relevance_score' => 0.8],
                    ['index' => 0, 'relevance_score' => 0.4],
                ],
            ])),
        );

        $result = $processor->process(new UserMessage('Question'), $documents);

        $this->assertSame([$documents[2], $documents[0]], $result);
        $this->assertSame([0.8, 0.4], array_map(static fn (Document $document): ?float => $document->getScore(), $result));
    }
}
