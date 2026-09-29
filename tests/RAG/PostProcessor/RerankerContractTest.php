<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\PostProcessor;

use Closure;
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

use function array_is_list;

/**
 * What every remote reranker guarantees, whatever its API's request format.
 */
class RerankerContractTest extends TestCase
{
    use RecordsJsonRequests;

    /**
     * @return array<string, array{Closure(HttpClientInterface): PostProcessorInterface}>
     */
    public static function rerankers(): array
    {
        return [
            'cohere' => [static fn (HttpClientInterface $client): PostProcessorInterface => new CohereRerankerPostProcessor(key: 'key', httpClient: $client)],
            'jina' => [static fn (HttpClientInterface $client): PostProcessorInterface => new JinaRerankerPostProcessor(key: 'key', httpClient: $client)],
            'localai' => [static fn (HttpClientInterface $client): PostProcessorInterface => new LocalAIRerankerPostProcessor(key: 'key', httpClient: $client)],
        ];
    }

    /**
     * @return iterable<string, array{Closure(HttpClientInterface): PostProcessorInterface, array<string, mixed>, string}>
     */
    public static function malformedResponses(): iterable
    {
        $responses = [
            'no results' => [['id' => 'rerank-1'], 'The rerank response has no results.'],
            'an index that was not sent' => [
                ['results' => [['index' => 5, 'relevance_score' => 0.5]]],
                'The rerank response names document 5, which was not sent.',
            ],
            'a negative index' => [
                ['results' => [['index' => -1, 'relevance_score' => 0.5]]],
                'The rerank response names document -1, which was not sent.',
            ],
            'no index' => [
                ['results' => [['relevance_score' => 0.5]]],
                'The rerank response names document null, which was not sent.',
            ],
            'a repeated index' => [
                ['results' => [['index' => 0, 'relevance_score' => 0.9], ['index' => 0, 'relevance_score' => 0.8]]],
                'The rerank response names document 0 twice.',
            ],
            'a score that is not a number' => [
                ['results' => [['index' => 1, 'relevance_score' => 'high']]],
                'The rerank response gives document 1 a score that is not a number.',
            ],
        ];

        foreach (self::rerankers() as $reranker => [$factory]) {
            foreach ($responses as $case => [$response, $message]) {
                yield "{$reranker}: {$case}" => [$factory, $response, $message];
            }
        }
    }

    /**
     * @param Closure(HttpClientInterface): PostProcessorInterface $reranker
     */
    #[DataProvider('rerankers')]
    public function test_no_documents_are_reranked_without_calling_the_api(Closure $reranker): void
    {
        $this->assertSame([], $reranker($this->recordingClient())->process(new UserMessage('Question'), []));
        $this->assertSame([], $this->sentRequests);
    }

    /**
     * @param Closure(HttpClientInterface): PostProcessorInterface $reranker
     */
    #[DataProvider('rerankers')]
    public function test_a_keyed_document_array_is_sent_as_a_list_and_mapped_back_by_position(Closure $reranker): void
    {
        $first = new Document('First');
        $second = new Document('Second');
        $client = $this->recordingClient($this->jsonResponse(['results' => [
            ['index' => 1, 'relevance_score' => 0.9],
            ['index' => 0, 'relevance_score' => 0.1],
        ]]));

        $result = $reranker($client)->process(new UserMessage('Question'), [3 => $first, 7 => $second]);

        $this->assertTrue(array_is_list($this->sentJson()['documents']));
        $this->assertSame([$second, $first], $result);
        $this->assertSame(0.9, $second->getScore());
    }

    /**
     * @param Closure(HttpClientInterface): PostProcessorInterface $reranker
     * @param array<string, mixed> $response
     */
    #[DataProvider('malformedResponses')]
    public function test_a_malformed_response_raises_a_provider_exception(Closure $reranker, array $response, string $message): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage($message);

        $reranker($this->recordingClient($this->jsonResponse($response)))
            ->process(new UserMessage('Question'), [new Document('First'), new Document('Second')]);
    }
}
