<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\RAG\PostProcessor\LocalAIRerankerPostProcessor;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\RAG\VectorStore\WeaviateVectorStore;
use NeuronAI\Tests\Support\AssertsApiKeyConfinement;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill;

/**
 * A rejected key must not come back in the exception the application logs:
 * the failure describes the exchange (status, URL, vendor answer) while the key
 * stays in the one header that authenticates the request.
 */
class RemoteServiceCredentialsTrustBoundarySecurityTest extends TestCase
{
    use RecordsHttpRequests;
    use AssertsApiKeyConfinement;

    protected const SECRET = 'rag-SECRET-key-5e8d1f';

    /**
     * @return array<string, array{Closure(HttpClientInterface): mixed, string}>
     */
    public static function components(): array
    {
        return [
            'voyage embeddings' => [
                static fn (HttpClientInterface $client): array => (new VoyageEmbeddingsProvider(self::SECRET, 'voyage-3', httpClient: $client))->embedText('question'),
                'Authorization',
            ],
            'openai-like embeddings' => [
                static fn (HttpClientInterface $client): array => (new OpenAILikeEmbeddings('https://embeddings.internal/v1', self::SECRET, 'model', httpClient: $client))->embedText('question'),
                'Authorization',
            ],
            'localai reranker' => [
                static fn (HttpClientInterface $client): array => (new LocalAIRerankerPostProcessor(self::SECRET, httpClient: $client))->process(new UserMessage('question'), [new Document('text')]),
                'Authorization',
            ],
            'meilisearch' => [
                static fn (HttpClientInterface $client): MeilisearchVectorStore => new MeilisearchVectorStore('docs', 'http://meilisearch.internal:7700', self::SECRET, httpClient: $client),
                'Authorization',
            ],
            'qdrant' => [
                static fn (HttpClientInterface $client): QdrantVectorStore => new QdrantVectorStore('http://qdrant.internal:6333/collections/docs/', self::SECRET, httpClient: $client),
                'api-key',
            ],
            'weaviate' => [
                static fn (HttpClientInterface $client): WeaviateVectorStore => new WeaviateVectorStore('docs', 'http://weaviate.internal:8080', self::SECRET, httpClient: $client),
                'Authorization',
            ],
        ];
    }

    /**
     * @param Closure(HttpClientInterface): mixed $call
     */
    #[DataProvider('components')]
    public function test_a_rejected_key_is_confined_to_its_header_and_absent_from_the_exception(Closure $call, string $header): void
    {
        $client = $this->recordingClient(...array_fill(0, 3, new Response(401, body: '{"error":"invalid api key"}')));

        try {
            $call($client);
            $this->fail('A 401 answer must raise an HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringContainsString('invalid api key', $exception->getMessage());
            $this->assertStringNotContainsString(self::SECRET, $exception->getMessage());
        }

        $this->assertNotSame([], $this->sentRequests);
        foreach ($this->sentRequests as $entry) {
            $this->assertApiKeyTravelsOnlyIn($header, self::SECRET, $entry['request']);
        }
    }
}
