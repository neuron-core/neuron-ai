<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;

class OpenAIEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    /** @param array<int, float[]> $embeddings */
    protected function embeddingsResponse(array $embeddings): Response
    {
        $data = [];
        foreach ($embeddings as $index => $embedding) {
            $data[] = ['object' => 'embedding', 'index' => $index, 'embedding' => $embedding];
        }

        return $this->jsonResponse(['object' => 'list', 'data' => $data]);
    }

    /** @return Document[] */
    protected function documents(int $count): array
    {
        return array_map(static fn (int $index): Document => new Document("Document {$index}"), range(0, $count - 1));
    }

    public function test_embed_text_sends_the_text_with_default_dimensions(): void
    {
        $provider = new OpenAIEmbeddingsProvider(
            key: 'openai-key',
            model: 'text-embedding-3-small',
            httpClient: $this->recordingClient($this->embeddingsResponse([[0.1, -0.2]])),
        );

        $this->assertSame([0.1, -0.2], $provider->embedText('Hello world'));

        $request = $this->sentRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.openai.com/v1/embeddings', (string) $request->getUri());
        $this->assertSame('Bearer openai-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame([
            'model' => 'text-embedding-3-small',
            'input' => 'Hello world',
            'encoding_format' => 'float',
            'dimensions' => 1024,
        ], $this->sentJson());
    }

    public function test_dimensions_can_be_configured(): void
    {
        $provider = new OpenAIEmbeddingsProvider(
            key: 'openai-key',
            model: 'text-embedding-3-large',
            dimensions: 256,
            httpClient: $this->recordingClient($this->embeddingsResponse([[0.1]])),
        );

        $provider->embedText('Hello');

        $this->assertSame(256, $this->sentJson()['dimensions']);
    }

    public function test_without_dimensions_omits_the_parameter_for_models_that_reject_it(): void
    {
        $provider = (new OpenAIEmbeddingsProvider(
            key: 'openai-key',
            model: 'text-embedding-ada-002',
            httpClient: $this->recordingClient($this->embeddingsResponse([[0.1]]), $this->embeddingsResponse([[0.2]])),
        ))->withoutDimensions();

        $provider->embedText('Hello');
        $provider->embedDocuments([new Document('Hello')]);

        $this->assertArrayNotHasKey('dimensions', $this->sentJson(0));
        $this->assertArrayNotHasKey('dimensions', $this->sentJson(1));
    }

    public function test_null_dimensions_omit_the_parameter(): void
    {
        $provider = new OpenAIEmbeddingsProvider(
            key: 'openai-key',
            model: 'text-embedding-ada-002',
            dimensions: null,
            httpClient: $this->recordingClient($this->embeddingsResponse([[0.1]])),
        );

        $provider->embedText('Hello');

        $this->assertArrayNotHasKey('dimensions', $this->sentJson());
    }

    public function test_embed_documents_sends_contents_in_one_batch_and_maps_embeddings_back_in_order(): void
    {
        $documents = [new Document('First'), new Document('Second'), new Document('Third')];
        $provider = new OpenAIEmbeddingsProvider(
            key: 'openai-key',
            model: 'text-embedding-3-small',
            httpClient: $this->recordingClient($this->embeddingsResponse([[1.0], [2.0], [3.0]])),
        );

        $result = $provider->embedDocuments($documents);

        $this->assertSame($documents, $result);
        $this->assertSame([[1.0], [2.0], [3.0]], array_map(static fn (Document $document): ?array => $document->getEmbedding(), $result));
        $this->assertSame([
            'model' => 'text-embedding-3-small',
            'input' => ['First', 'Second', 'Third'],
            'encoding_format' => 'float',
            'dimensions' => 1024,
        ], $this->sentJson());
    }

    /** @return iterable<string, array{int, int[]}> */
    public static function batchBoundaries(): iterable
    {
        yield 'one document' => [1, [1]];
        yield 'exactly one full batch' => [100, [100]];
        yield 'one over a full batch' => [101, [100, 1]];
        yield 'two full batches' => [200, [100, 100]];
    }

    /** @param int[] $batchSizes */
    #[DataProvider('batchBoundaries')]
    public function test_embed_documents_splits_requests_at_one_hundred_inputs(int $count, array $batchSizes): void
    {
        $responses = [];
        $offset = 0;
        foreach ($batchSizes as $size) {
            $responses[] = $this->embeddingsResponse(array_map(static fn (int $index): array => [(float) $index], range($offset, $offset + $size - 1)));
            $offset += $size;
        }
        $documents = $this->documents($count);
        $provider = new OpenAIEmbeddingsProvider(key: 'openai-key', model: 'model', httpClient: $this->recordingClient(...$responses));

        $result = $provider->embedDocuments($documents);

        $this->assertCount(count($batchSizes), $this->sentRequests);
        foreach ($batchSizes as $batch => $size) {
            $this->assertCount($size, $this->sentJson($batch)['input']);
        }
        $this->assertSame($documents, $result);
        foreach ($documents as $index => $document) {
            $this->assertSame([(float) $index], $document->getEmbedding());
        }
    }

    public function test_embed_documents_without_documents_sends_nothing(): void
    {
        $provider = new OpenAIEmbeddingsProvider(key: 'openai-key', model: 'model', httpClient: $this->recordingClient());

        $this->assertSame([], $provider->embedDocuments([]));
        $this->assertSame([], $this->sentRequests);
    }

    public function test_an_http_error_propagates_without_the_api_key(): void
    {
        $provider = new OpenAIEmbeddingsProvider(
            key: 'sk-openai-secret',
            model: 'model',
            httpClient: $this->recordingClient($this->jsonResponse(['error' => ['message' => 'Rate limit reached']], 429)),
        );

        try {
            $provider->embedText('Hello');
            $this->fail('An HTTP error must fail the embedding.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->response?->statusCode);
            $this->assertStringContainsString('Rate limit reached', $exception->getMessage());
            $this->assertStringNotContainsString('sk-openai-secret', $exception->getMessage());
        }
    }
}
