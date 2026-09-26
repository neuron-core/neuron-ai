<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use GuzzleHttp\Psr7\Response;
use NeuronAI\RAG\VectorStore\MeilisearchVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Tests\RAG\VectorStore\Stub\RecordsVectorStoreRequests;
use PHPUnit\Framework\TestCase;

class MeilisearchTopKReproTest extends TestCase
{
    use RecordsVectorStoreRequests;

    public function test_search_honors_a_top_k_above_twenty(): void
    {
        $store = new MeilisearchVectorStore('docs', httpClient: $this->recordingClient(
            new Response(200),
            $this->jsonResponse(['taskUid' => 1]),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['taskUid' => 2]),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['hits' => []]),
        ));

        $store->search(new SearchRequest([1.0], topK: 50));

        $this->assertSame(50, $this->sentJson(5)['limit']);
    }

    public function test_search_honors_a_store_default_top_k_above_twenty(): void
    {
        $store = new MeilisearchVectorStore('docs', topK: 30, httpClient: $this->recordingClient(
            new Response(200),
            $this->jsonResponse(['taskUid' => 1]),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['taskUid' => 2]),
            $this->jsonResponse(['status' => 'succeeded']),
            $this->jsonResponse(['hits' => []]),
        ));

        $store->search(new SearchRequest([1.0]));

        $this->assertSame(30, $this->sentJson(5)['limit']);
    }
}
