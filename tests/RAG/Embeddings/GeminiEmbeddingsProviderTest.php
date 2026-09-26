<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\Embeddings;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\GeminiEmbeddingsProvider;
use NeuronAI\Tests\RAG\Stub\RecordsJsonRequests;
use PHPUnit\Framework\TestCase;

use function array_map;

class GeminiEmbeddingsProviderTest extends TestCase
{
    use RecordsJsonRequests;

    public function test_embed_text_calls_the_model_embed_content_endpoint(): void
    {
        $provider = new GeminiEmbeddingsProvider(
            key: 'gemini-key',
            model: 'gemini-embedding-001',
            httpClient: $this->recordingClient($this->jsonResponse(['embedding' => ['values' => [0.1, 0.2]]])),
        );

        $this->assertSame([0.1, 0.2], $provider->embedText('Hello'));

        $request = $this->sentRequest();
        $this->assertSame(
            'POST https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent',
            $this->sentTargets()[0],
        );
        $this->assertSame('gemini-key', $request->getHeaderLine('x-goog-api-key'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame(['content' => ['parts' => [['text' => 'Hello']]]], $this->sentJson());
    }

    public function test_the_api_key_travels_in_a_header_never_in_the_url(): void
    {
        $provider = new GeminiEmbeddingsProvider(
            key: 'gemini-secret-key',
            model: 'gemini-embedding-001',
            httpClient: $this->recordingClient($this->jsonResponse(['error' => ['message' => 'Quota exceeded']], 429)),
        );

        try {
            $provider->embedText('Hello');
            $this->fail('An HTTP error must fail the embedding.');
        } catch (HttpException $exception) {
            $this->assertStringNotContainsString('gemini-secret-key', $exception->getMessage());
        }

        $this->assertStringNotContainsString('gemini-secret-key', (string) $this->sentRequest()->getUri());
        $this->assertSame('', $this->sentRequest()->getHeaderLine('Authorization'));
    }

    public function test_config_is_merged_into_the_request_body(): void
    {
        $provider = new GeminiEmbeddingsProvider(
            key: 'gemini-key',
            model: 'gemini-embedding-001',
            config: ['taskType' => 'RETRIEVAL_DOCUMENT', 'outputDimensionality' => 768],
            httpClient: $this->recordingClient($this->jsonResponse(['embedding' => ['values' => [0.1]]])),
        );

        $provider->embedText('Hello');

        $this->assertSame([
            'content' => ['parts' => [['text' => 'Hello']]],
            'taskType' => 'RETRIEVAL_DOCUMENT',
            'outputDimensionality' => 768,
        ], $this->sentJson());
    }

    public function test_embed_documents_embeds_each_document_in_order(): void
    {
        $documents = [new Document('First'), new Document('Second')];
        $provider = new GeminiEmbeddingsProvider(
            key: 'gemini-key',
            model: 'gemini-embedding-001',
            httpClient: $this->recordingClient(
                $this->jsonResponse(['embedding' => ['values' => [1.0]]]),
                $this->jsonResponse(['embedding' => ['values' => [2.0]]]),
            ),
        );

        $result = $provider->embedDocuments($documents);

        $this->assertSame($documents, $result);
        $this->assertSame([[1.0], [2.0]], array_map(static fn (Document $document): ?array => $document->getEmbedding(), $result));
        $this->assertSame('First', $this->sentJson(0)['content']['parts'][0]['text']);
        $this->assertSame('Second', $this->sentJson(1)['content']['parts'][0]['text']);
    }
}
