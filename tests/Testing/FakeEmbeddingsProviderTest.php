<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class FakeEmbeddingsProviderTest extends TestCase
{
    public function test_embed_text_returns_float_array(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $embedding = $provider->embedText('Hello world');

        $this->assertCount(8, $embedding);

        foreach ($embedding as $value) {
            $this->assertIsFloat($value);
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThanOrEqual(1.0, $value);
        }
    }

    public function test_embed_text_is_deterministic(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $first = $provider->embedText('Hello');
        $second = $provider->embedText('Hello');

        $this->assertSame($first, $second);
    }

    public function test_different_texts_produce_different_embeddings(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $a = $provider->embedText('Hello');
        $b = $provider->embedText('Goodbye');

        $this->assertNotSame($a, $b);
    }

    public function test_custom_dimensions(): void
    {
        $provider = new FakeEmbeddingsProvider(dimensions: 4);

        $embedding = $provider->embedText('Test');

        $this->assertCount(4, $embedding);
    }

    public function test_embed_document(): void
    {
        $provider = new FakeEmbeddingsProvider();
        $document = new Document('Some content');

        $result = $provider->embedDocument($document);

        $this->assertSame($document, $result);
        $this->assertCount(8, $document->embedding);
    }

    public function test_embed_documents(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $docs = [
            new Document('First'),
            new Document('Second'),
        ];

        $result = $provider->embedDocuments($docs);

        $this->assertCount(2, $result);
        $this->assertNotEmpty($result[0]->embedding);
        $this->assertNotEmpty($result[1]->embedding);
    }

    public function test_records_embedded_texts(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $provider->embedText('Hello');
        $provider->embedText('World');

        $this->assertSame(['Hello', 'World'], $provider->getRecorded());
        $this->assertSame(2, $provider->getCallCount());
    }

    public function test_assert_call_count(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $provider->embedText('Test');

        $provider->assertCallCount(1);
        $this->addToAssertionCount(1);
    }

    public function test_assert_call_count_fails(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $this->expectException(AssertionFailedError::class);
        $provider->assertCallCount(1);
    }

    public function test_assert_embedded_text(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $provider->embedText('Hello world');

        $provider->assertEmbeddedText('Hello world');
        $this->addToAssertionCount(1);
    }

    public function test_assert_embedded_text_fails(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $this->expectException(AssertionFailedError::class);
        $provider->assertEmbeddedText('Never embedded');
    }

    public function test_assert_nothing_embedded(): void
    {
        $provider = new FakeEmbeddingsProvider();

        $provider->assertNothingEmbedded();
        $this->addToAssertionCount(1);
    }

    public function test_assert_nothing_embedded_fails(): void
    {
        $provider = new FakeEmbeddingsProvider();
        $provider->embedText('Test');

        $this->expectException(AssertionFailedError::class);
        $provider->assertNothingEmbedded();
    }

    public function test_static_make(): void
    {
        $provider = FakeEmbeddingsProvider::make(4);

        $embedding = $provider->embedText('Test');

        $this->assertCount(4, $embedding);
    }
}
