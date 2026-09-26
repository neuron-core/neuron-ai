<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Closure;

class RerankerDocumentListTest extends TestCase
{
    use RecordsJsonRequests;

    /** @return array<string, array{Closure(HttpClientInterface): PostProcessorInterface, list<mixed>}> */
    public static function rerankers(): array
    {
        return [
            'cohere' => [fn (HttpClientInterface $client): PostProcessorInterface => new CohereRerankerPostProcessor(key: 'key', httpClient: $client), ['Rome', 'Paris']],
            'jina' => [fn (HttpClientInterface $client): PostProcessorInterface => new JinaRerankerPostProcessor(key: 'key', httpClient: $client), [['text' => 'Rome'], ['text' => 'Paris']]],
            'localai' => [fn (HttpClientInterface $client): PostProcessorInterface => new LocalAIRerankerPostProcessor(key: 'key', httpClient: $client), ['Rome', 'Paris']],
        ];
    }

    /** @param Closure(HttpClientInterface): PostProcessorInterface $make */
    #[DataProvider('rerankers')]
    public function test_documents_with_non_sequential_keys_are_reranked(Closure $make, array $expectedPayload): void
    {
        // array_filter() without array_values() in a custom post-processor keeps the original keys.
        $documents = [1 => new Document('Rome'), 3 => new Document('Paris')];
        $processor = $make($this->recordingClient($this->jsonResponse(['results' => [['index' => 1, 'relevance_score' => 0.9]]])));

        $result = $processor->process(new UserMessage('Capital of France?'), $documents);

        $this->assertSame($expectedPayload, $this->sentJson()['documents']);
        $this->assertSame([$documents[3]], $result);
        $this->assertSame(0.9, $documents[3]->getScore());
    }
}
