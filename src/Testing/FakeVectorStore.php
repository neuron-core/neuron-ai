<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\StaticConstructor;
use PHPUnit\Framework\Assert;

use function array_filter;
use function array_merge;
use function array_values;
use function count;

/**
 * @method static static make(array $searchResults = [])
 */
class FakeVectorStore implements VectorStoreInterface
{
    use StaticConstructor;
    /** @var Document[] */
    protected array $documents = [];

    protected int $searchCount = 0;

    /** @var array<array{method: string, args: array<mixed>}> */
    protected array $recorded = [];

    /**
     * @param Document[] $searchResults Documents to return from similaritySearch()
     */
    public function __construct(protected array $searchResults = [])
    {
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->documents[] = $document;

        $this->recorded[] = ['method' => 'addDocument', 'args' => [$document]];

        return $this;
    }

    /**
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->documents = array_merge($this->documents, $documents);

        $this->recorded[] = ['method' => 'addDocuments', 'args' => $documents];

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        $this->documents = array_values(array_filter(
            $this->documents,
            fn (Document $doc): bool => $doc->sourceType !== $sourceType || $doc->sourceName !== $sourceName
        ));

        $this->recorded[] = ['method' => 'deleteBySource', 'args' => [$sourceType, $sourceName]];

        return $this;
    }

    /**
     * @param float[] $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): array
    {
        $this->searchCount++;

        $this->recorded[] = ['method' => 'similaritySearch', 'args' => [$embedding]];

        return $this->searchResults;
    }

    /**
     * Set or replace the documents that similaritySearch() will return.
     *
     * @param Document[] $documents
     */
    public function setSearchResults(array $documents): self
    {
        $this->searchResults = $documents;
        return $this;
    }

    /**
     * @return Document[]
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    /**
     * @return array<array{method: string, args: array<mixed>}>
     */
    public function getRecorded(): array
    {
        return $this->recorded;
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertSearchCount(int $expected): void
    {
        Assert::assertSame(
            $expected,
            $this->searchCount,
            "Expected {$expected} similarity searches, got {$this->searchCount}."
        );
    }

    public function assertDocumentCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->documents,
            "Expected {$expected} documents in store, got " . count($this->documents) . '.'
        );
    }

    public function assertHasDocumentWithContent(string $content): void
    {
        $matched = false;

        foreach ($this->documents as $document) {
            if ($document->content === $content) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, "No document found with content: {$content}");
    }

    public function assertNothingStored(): void
    {
        Assert::assertEmpty(
            $this->documents,
            'Expected no documents in store, but ' . count($this->documents) . ' were found.'
        );
    }
}
