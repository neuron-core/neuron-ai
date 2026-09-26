<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\CohereEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\TestCase;

class CohereDocumentInputTypeTest extends TestCase
{
    use RecordsJsonRequests;

    public function test_documents_are_embedded_for_storage_and_queries_for_search(): void
    {
        $provider = new CohereEmbeddingsProvider(
            key: 'key',
            model: 'embed-v4.0',
            httpClient: $this->recordingClient(
                $this->jsonResponse(['embeddings' => ['float' => [[0.1]]]]),
                $this->jsonResponse(['embeddings' => ['float' => [[0.2]]]]),
                $this->jsonResponse(['embeddings' => ['float' => [[0.3]]]]),
            ),
        );

        $provider->embedDocuments([new Document('Stored passage')]);
        $single = $provider->embedDocument(new Document('Stored conversation turn'));
        $provider->embedText('User question');

        $this->assertSame('search_document', $this->sentJson(0)['input_type']);
        $this->assertSame('search_document', $this->sentJson(1)['input_type']);
        $this->assertSame([0.2], $single->getEmbedding());
        $this->assertSame('search_query', $this->sentJson(2)['input_type']);
    }
}
