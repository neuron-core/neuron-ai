<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class OpenAILikeEmbeddingsTest extends TestCase
{
    use RecordsJsonRequests;

    #[TestWith(['https://llm.internal.example/v1'])]
    #[TestWith(['https://llm.internal.example/v1/'])]
    public function test_requests_target_the_configured_compatible_api(string $baseUri): void
    {
        $provider = new OpenAILikeEmbeddings(
            baseUri: $baseUri,
            key: 'compatible-key',
            model: 'nomic-embed-text',
            dimensions: 768,
            httpClient: $this->recordingClient($this->jsonResponse(['data' => [['index' => 0, 'embedding' => [0.5]]]])),
        );

        $this->assertSame([0.5], $provider->embedText('Hello'));

        $this->assertSame(['POST https://llm.internal.example/v1/embeddings'], $this->sentTargets());
        $this->assertSame('Bearer compatible-key', $this->sentRequest()->getHeaderLine('Authorization'));
        $this->assertSame([
            'model' => 'nomic-embed-text',
            'input' => 'Hello',
            'encoding_format' => 'float',
            'dimensions' => 768,
        ], $this->sentJson());
    }
}
