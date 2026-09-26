<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAIEmbeddingsProvider;
use NeuronAI\RAG\Embeddings\VoyageEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\TestCase;

class EmbeddingsResponseIndexTest extends TestCase
{
    use RecordsJsonRequests;

    public function test_openai_embeddings_are_matched_to_documents_by_their_response_index(): void
    {
        $first = new Document('First');
        $second = new Document('Second');
        $provider = new OpenAIEmbeddingsProvider(key: 'key', model: 'model', httpClient: $this->recordingClient($this->outOfOrderResponse()));

        $provider->embedDocuments([$first, $second]);

        $this->assertSame([1.0], $first->getEmbedding());
        $this->assertSame([2.0], $second->getEmbedding());
    }

    public function test_voyage_embeddings_are_matched_to_documents_by_their_response_index(): void
    {
        $first = new Document('First');
        $second = new Document('Second');
        $provider = new VoyageEmbeddingsProvider(key: 'key', model: 'model', httpClient: $this->recordingClient($this->outOfOrderResponse()));

        $provider->embedDocuments([$first, $second]);

        $this->assertSame([1.0], $first->getEmbedding());
        $this->assertSame([2.0], $second->getEmbedding());
    }

    protected function outOfOrderResponse(): Response
    {
        return $this->jsonResponse([
            'data' => [
                ['index' => 1, 'embedding' => [2.0]],
                ['index' => 0, 'embedding' => [1.0]],
            ],
        ]);
    }
}
