<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RerankerEmptyDocumentsTest extends TestCase
{
    use RecordsJsonRequests;

    /** @return array<string, array{string}> */
    public static function rerankers(): array
    {
        return [
            'cohere' => [CohereRerankerPostProcessor::class],
            'jina' => [JinaRerankerPostProcessor::class],
            'localai' => [LocalAIRerankerPostProcessor::class],
        ];
    }

    /** @param class-string<PostProcessorInterface> $reranker */
    #[DataProvider('rerankers')]
    public function test_nothing_retrieved_means_nothing_to_rerank_and_no_api_call(string $reranker): void
    {
        $processor = new $reranker(key: 'key', httpClient: $this->recordingClient());

        $this->assertSame([], $processor->process(new UserMessage('Question'), []));
        $this->assertSame([], $this->sentRequests);
    }
}
