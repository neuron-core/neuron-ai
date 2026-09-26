<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\MistralEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\TestCase;

class MistralEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    public function test_requests_target_the_mistral_api(): void
    {
        $provider = new MistralEmbeddingsProvider(
            key: 'mistral-key',
            model: 'mistral-embed',
            httpClient: $this->recordingClient(
                $this->jsonResponse(['data' => [['index' => 0, 'embedding' => [0.5]]]]),
                $this->jsonResponse(['data' => [['index' => 0, 'embedding' => [0.25]]]]),
            ),
        );

        $this->assertSame([0.5], $provider->embedText('Hello'));
        $this->assertSame([0.25], $provider->embedDocuments([new Document('Hello')])[0]->getEmbedding());

        $this->assertSame([
            'POST https://api.mistral.ai/v1/embeddings',
            'POST https://api.mistral.ai/v1/embeddings',
        ], $this->sentTargets());
        $this->assertSame('Bearer mistral-key', $this->sentRequest()->getHeaderLine('Authorization'));
        $this->assertSame('mistral-embed', $this->sentJson()['model']);
    }
}
