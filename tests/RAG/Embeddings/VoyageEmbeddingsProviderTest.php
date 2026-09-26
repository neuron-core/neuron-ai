<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function range;

class VoyageEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    /** @param int[] $indexes */
    protected function embeddingsResponse(array $indexes): Response
    {
        return $this->jsonResponse(['data' => array_map(
            static fn (int $index): array => ['object' => 'embedding', 'index' => $index, 'embedding' => [(float) $index]],
            $indexes,
        )]);
    }

    public function test_embed_text_sends_the_text_and_requested_dimension(): void
    {
        $provider = new VoyageEmbeddingsProvider(
            key: 'voyage-key',
            model: 'voyage-3',
            dimensions: 512,
            httpClient: $this->recordingClient($this->jsonResponse(['data' => [['index' => 0, 'embedding' => [0.1, 0.2]]]])),
        );

        $this->assertSame([0.1, 0.2], $provider->embedText('Hello'));

        $request = $this->sentRequest();
        $this->assertSame('POST https://api.voyageai.com/v1/embeddings', $this->sentTargets()[0]);
        $this->assertSame('Bearer voyage-key', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['model' => 'voyage-3', 'input' => 'Hello', 'output_dimension' => 512], $this->sentJson());
    }

    public function test_the_model_default_dimension_is_requested_as_null(): void
    {
        $provider = new VoyageEmbeddingsProvider(
            key: 'voyage-key',
            model: 'voyage-3',
            httpClient: $this->recordingClient($this->embeddingsResponse([0])),
        );

        $provider->embedText('Hello');

        $this->assertArrayHasKey('output_dimension', $this->sentJson());
        $this->assertNull($this->sentJson()['output_dimension']);
    }

    /** @return iterable<string, array{int, int[]}> */
    public static function batchBoundaries(): iterable
    {
        yield 'exactly one full batch' => [100, [100]];
        yield 'one over a full batch' => [101, [100, 1]];
    }

    /** @param int[] $batchSizes */
    #[DataProvider('batchBoundaries')]
    public function test_embed_documents_splits_requests_at_one_hundred_inputs_and_keeps_order(int $count, array $batchSizes): void
    {
        $responses = [];
        $offset = 0;
        foreach ($batchSizes as $size) {
            $responses[] = $this->embeddingsResponse(range($offset, $offset + $size - 1));
            $offset += $size;
        }
        $documents = array_map(static fn (int $index): Document => new Document("Document {$index}"), range(0, $count - 1));
        $provider = new VoyageEmbeddingsProvider(key: 'voyage-key', model: 'voyage-3', httpClient: $this->recordingClient(...$responses));

        $result = $provider->embedDocuments($documents);

        $this->assertCount(count($batchSizes), $this->sentRequests);
        foreach ($batchSizes as $batch => $size) {
            $this->assertCount($size, $this->sentJson($batch)['input']);
        }
        $this->assertSame('Document 0', $this->sentJson()['input'][0]);
        $this->assertSame($documents, $result);
        foreach ($documents as $index => $document) {
            $this->assertSame([(float) $index], $document->getEmbedding());
        }
    }

    public function test_embed_documents_without_documents_sends_nothing(): void
    {
        $provider = new VoyageEmbeddingsProvider(key: 'voyage-key', model: 'voyage-3', httpClient: $this->recordingClient());

        $this->assertSame([], $provider->embedDocuments([]));
        $this->assertSame([], $this->sentRequests);
    }
}
