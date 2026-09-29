<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function count;
use function range;

class VoyageEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    /**
     * One embedding per value, indexed by its position in this request, as the API does.
     *
     * @param int[] $values
     */
    protected function embeddingsResponse(array $values): Response
    {
        return $this->jsonResponse(['data' => array_map(
            static fn (int $position, int $value): array => ['object' => 'embedding', 'index' => $position, 'embedding' => [(float) $value]],
            array_keys($values),
            $values,
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

    public function test_embeddings_are_matched_to_documents_by_the_returned_index(): void
    {
        $documents = [new Document('First'), new Document('Second'), new Document('Third')];
        $provider = new VoyageEmbeddingsProvider(key: 'voyage-key', model: 'voyage-3', httpClient: $this->recordingClient($this->jsonResponse(['data' => [
            ['object' => 'embedding', 'index' => 2, 'embedding' => [3.0]],
            ['object' => 'embedding', 'index' => 0, 'embedding' => [1.0]],
            ['object' => 'embedding', 'index' => 1, 'embedding' => [2.0]],
        ]])));

        $provider->embedDocuments($documents);

        $this->assertSame([[1.0], [2.0], [3.0]], array_map(static fn (Document $document): ?array => $document->getEmbedding(), $documents));
    }

    public function test_embed_documents_without_documents_sends_nothing(): void
    {
        $provider = new VoyageEmbeddingsProvider(key: 'voyage-key', model: 'voyage-3', httpClient: $this->recordingClient());

        $this->assertSame([], $provider->embedDocuments([]));
        $this->assertSame([], $this->sentRequests);
    }
}
