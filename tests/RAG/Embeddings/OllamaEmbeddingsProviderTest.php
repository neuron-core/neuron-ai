<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class OllamaEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    public function test_embed_text_calls_the_local_embed_endpoint_without_credentials(): void
    {
        $provider = new OllamaEmbeddingsProvider(
            model: 'nomic-embed-text',
            httpClient: $this->recordingClient($this->jsonResponse(['model' => 'nomic-embed-text', 'embeddings' => [[0.1, 0.2]]])),
        );

        $this->assertSame([0.1, 0.2], $provider->embedText('Hello'));

        $this->assertSame(['POST http://localhost:11434/api/embed'], $this->sentTargets());
        $this->assertSame('', $this->sentRequest()->getHeaderLine('Authorization'));
        $this->assertSame(['model' => 'nomic-embed-text', 'input' => 'Hello'], $this->sentJson());
    }

    #[TestWith(['http://ollama:11434/api'])]
    #[TestWith(['http://ollama:11434/api/'])]
    public function test_custom_url_is_joined_with_a_single_slash(string $url): void
    {
        $provider = new OllamaEmbeddingsProvider(
            model: 'nomic-embed-text',
            url: $url,
            httpClient: $this->recordingClient($this->jsonResponse(['embeddings' => [[0.1]]])),
        );

        $provider->embedText('Hello');

        $this->assertSame(['POST http://ollama:11434/api/embed'], $this->sentTargets());
    }

    public function test_parameters_are_merged_into_the_request_body(): void
    {
        $provider = new OllamaEmbeddingsProvider(
            model: 'nomic-embed-text',
            parameters: ['truncate' => false, 'keep_alive' => '5m'],
            httpClient: $this->recordingClient($this->jsonResponse(['embeddings' => [[0.1]]])),
        );

        $provider->embedText('Hello');

        $this->assertSame(
            ['model' => 'nomic-embed-text', 'input' => 'Hello', 'truncate' => false, 'keep_alive' => '5m'],
            $this->sentJson(),
        );
    }
}
