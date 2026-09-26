<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\CohereRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\JinaRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RerankerResponseValidationTest extends TestCase
{
    use RecordsJsonRequests;

    /** @return array<string, array{string, string}> */
    public static function malformedResponses(): array
    {
        $cases = [];
        $bodies = [
            'out-of-range index' => '{"results":[{"index":7,"relevance_score":0.5}]}',
            'negative index' => '{"results":[{"index":-1,"relevance_score":0.5}]}',
            'duplicate index' => '{"results":[{"index":0,"relevance_score":0.9},{"index":0,"relevance_score":0.1}]}',
            'missing results' => '{"error":"nope"}',
            'non-JSON body' => '<html>gateway</html>',
        ];
        foreach ([CohereRerankerPostProcessor::class, JinaRerankerPostProcessor::class, LocalAIRerankerPostProcessor::class] as $class) {
            foreach ($bodies as $label => $body) {
                $cases["{$class}: {$label}"] = [$class, $body];
            }
        }
        return $cases;
    }

    /** @param class-string<PostProcessorInterface> $class */
    #[DataProvider('malformedResponses')]
    public function test_a_malformed_rerank_response_is_rejected_with_a_provider_exception(string $class, string $body): void
    {
        $client = $this->recordingClient(new Response(200, ['Content-Type' => 'application/json'], $body));
        $processor = $this->reranker($class, $client);

        $this->expectException(ProviderException::class);

        $processor->process(new UserMessage('Question'), [new Document('Only document')]);
    }

    /** @param class-string<PostProcessorInterface> $class */
    protected function reranker(string $class, HttpClientInterface $client): PostProcessorInterface
    {
        return new $class(key: 'key', httpClient: $client);
    }
}
