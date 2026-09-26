<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\CohereEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;

class CohereEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    /** @param int[] $indexes */
    protected function embeddingsResponse(array $indexes): Response
    {
        return $this->jsonResponse([
            'id' => 'embed-id',
            'embeddings' => ['float' => array_map(static fn (int $index): array => [(float) $index], $indexes)],
        ]);
    }

    public function test_embed_text_sends_a_float_search_query_embedding_request(): void
    {
        $provider = new CohereEmbeddingsProvider(
            key: 'cohere-key',
            model: 'embed-v4.0',
            httpClient: $this->recordingClient($this->jsonResponse(['embeddings' => ['float' => [[0.1, -0.3]]]])),
        );

        $this->assertSame([0.1, -0.3], $provider->embedText('Hello'));

        $request = $this->sentRequest();
        $this->assertSame('POST https://api.cohere.com/v2/embed', $this->sentTargets()[0]);
        $this->assertSame('Bearer cohere-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'model' => 'embed-v4.0',
            'texts' => ['Hello'],
            'embedding_types' => ['float'],
            'input_type' => 'search_query',
        ], $this->sentJson());
    }

    public function test_parameters_extend_the_request_and_override_the_input_type(): void
    {
        $provider = new CohereEmbeddingsProvider(
            key: 'cohere-key',
            model: 'embed-v4.0',
            parameters: ['input_type' => 'classification', 'truncate' => 'END'],
            httpClient: $this->recordingClient($this->embeddingsResponse([0])),
        );

        $provider->embedText('Hello');

        $this->assertSame('classification', $this->sentJson()['input_type']);
        $this->assertSame('END', $this->sentJson()['truncate']);
        $this->assertSame(['float'], $this->sentJson()['embedding_types']);
    }

    /** @return iterable<string, array{int, int[]}> */
    public static function batchBoundaries(): iterable
    {
        yield 'one document' => [1, [1]];
        yield 'exactly one full batch' => [96, [96]];
        yield 'one over a full batch' => [97, [96, 1]];
    }

    /** @param int[] $batchSizes */
    #[DataProvider('batchBoundaries')]
    public function test_embed_documents_splits_requests_at_ninety_six_texts_and_keeps_order(int $count, array $batchSizes): void
    {
        $responses = [];
        $offset = 0;
        foreach ($batchSizes as $size) {
            $responses[] = $this->embeddingsResponse(range($offset, $offset + $size - 1));
            $offset += $size;
        }
        $documents = array_map(static fn (int $index): Document => new Document("Document {$index}"), range(0, $count - 1));
        $provider = new CohereEmbeddingsProvider(key: 'cohere-key', model: 'embed-v4.0', httpClient: $this->recordingClient(...$responses));

        $result = $provider->embedDocuments($documents);

        $this->assertCount(count($batchSizes), $this->sentRequests);
        foreach ($batchSizes as $batch => $size) {
            $this->assertCount($size, $this->sentJson($batch)['texts']);
            $this->assertSame(['float'], $this->sentJson($batch)['embedding_types']);
        }
        $this->assertSame($documents, $result);
        foreach ($documents as $index => $document) {
            $this->assertSame([(float) $index], $document->getEmbedding());
        }
    }

    public function test_embed_documents_without_documents_sends_nothing(): void
    {
        $provider = new CohereEmbeddingsProvider(key: 'cohere-key', model: 'embed-v4.0', httpClient: $this->recordingClient());

        $this->assertSame([], $provider->embedDocuments([]));
        $this->assertSame([], $this->sentRequests);
    }

    public function test_an_http_error_propagates_without_the_api_key(): void
    {
        $provider = new CohereEmbeddingsProvider(
            key: 'cohere-secret-key',
            model: 'embed-v4.0',
            httpClient: $this->recordingClient($this->jsonResponse(['message' => 'invalid api token'], 401)),
        );

        try {
            $provider->embedText('Hello');
            $this->fail('An HTTP error must fail the embedding.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertStringNotContainsString('cohere-secret-key', $exception->getMessage());
        }
    }
}
