<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\QdrantVectorStore;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class QdrantIdReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_integer_ids_are_sent_as_unsigned_integers(): void
    {
        $store = new QdrantVectorStore(
            collectionUrl: 'http://qdrant.test:6333/collections/docs',
            httpClient: $this->recordingClient($this->jsonResponse(['result' => ['exists' => true]]), new Response(200)),
        );

        $store->addDocument((new Document('Hello'))->setId(42)->setEmbedding([1, 0]));

        $this->assertSame(42, $this->sentJson(1)['points'][0]['id']);
    }

    public function test_uuid_ids_are_sent_unchanged(): void
    {
        $store = new QdrantVectorStore(
            collectionUrl: 'http://qdrant.test:6333/collections/docs',
            httpClient: $this->recordingClient($this->jsonResponse(['result' => ['exists' => true]]), new Response(200)),
        );

        $store->addDocument((new Document('Hello'))->setId('11111111-1111-1111-1111-111111111111')->setEmbedding([1, 0]));

        $this->assertSame('11111111-1111-1111-1111-111111111111', $this->sentJson(1)['points'][0]['id']);
    }
}
