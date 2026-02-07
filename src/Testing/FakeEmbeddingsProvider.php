<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\StaticConstructor;
use PHPUnit\Framework\Assert;

use function array_map;
use function array_slice;
use function count;
use function md5;
use function ord;
use function str_split;

class FakeEmbeddingsProvider implements EmbeddingsProviderInterface
{
    use StaticConstructor;

    /** @var string[] */
    protected array $recorded = [];

    public function __construct(protected int $dimensions = 8)
    {
    }

    /**
     * Generate a deterministic embedding from the text.
     *
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $this->recorded[] = $text;

        return $this->deterministicVector($text);
    }

    public function embedDocument(Document $document): Document
    {
        $text = $document->formattedContent ?? $document->content;
        $document->embedding = $this->embedText($text);
        return $document;
    }

    /**
     * @param Document[] $documents
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        foreach ($documents as $document) {
            $this->embedDocument($document);
        }

        return $documents;
    }

    /**
     * @return string[]
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    public function getCallCount(): int
    {
        return count($this->recorded);
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertCallCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->recorded,
            "Expected {$expected} embedding calls, got " . count($this->recorded) . '.'
        );
    }

    public function assertEmbeddedText(string $expected): void
    {
        Assert::assertContains(
            $expected,
            $this->recorded,
            "Expected text was never embedded: {$expected}"
        );
    }

    public function assertNothingEmbedded(): void
    {
        Assert::assertEmpty(
            $this->recorded,
            'Expected no embedding calls, but ' . count($this->recorded) . ' were recorded.'
        );
    }

    /**
     * Generate a deterministic vector from text using its MD5 hash.
     *
     * @return float[]
     */
    protected function deterministicVector(string $text): array
    {
        $hash = md5($text);
        $bytes = str_split($hash);

        return array_map(
            fn (string $char): float => ord($char) / 255.0,
            array_slice($bytes, 0, $this->dimensions)
        );
    }
}
