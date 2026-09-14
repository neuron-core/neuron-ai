<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\StaticConstructor;
use PHPUnit\Framework\Assert;

use function array_map;
use function count;
use function md5;
use function ord;
use function str_split;
use function strlen;
use function substr;

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
        $document->setEmbedding($this->embedText($document->getContent()));
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
     * Generate a deterministic vector from the text: MD5 rounds over the text
     * and a counter supply as many bytes as there are dimensions.
     *
     * @return float[]
     */
    protected function deterministicVector(string $text): array
    {
        $bytes = '';
        for ($round = 0; strlen($bytes) < $this->dimensions; $round++) {
            $bytes .= md5($text . $round, true);
        }

        return array_map(
            fn (string $byte): float => ord($byte) / 255.0,
            str_split(substr($bytes, 0, $this->dimensions))
        );
    }
}
