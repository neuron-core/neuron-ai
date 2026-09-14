<?php

declare(strict_types=1);

namespace NeuronAI\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\HasDocumentSchema;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\StaticConstructor;
use PHPUnit\Framework\Assert;

use function array_filter;
use function array_merge;
use function array_slice;
use function array_values;
use function count;

/**
 * @method static static make(array $searchResults = [], ?DocumentSchema $schema = null)
 */
class FakeVectorStore implements VectorStoreInterface
{
    use StaticConstructor;
    use HasDocumentSchema;

    /** @var Document[] */
    protected array $documents = [];

    /** @var VectorStoreRecord[] */
    protected array $recorded = [];

    /**
     * @param Document[] $searchResults Documents to return from search()
     */
    public function __construct(protected array $searchResults = [], ?DocumentSchema $schema = null)
    {
        $this->initializeSchema($schema);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->validateDocument($document);
        $this->documents[] = $document;

        $this->recorded[] = new VectorStoreRecord('addDocument', documents: [$document]);

        return $this;
    }

    /**
     * @param Document[] $documents
     */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->validateDocuments($documents);
        $this->documents = array_merge($this->documents, $documents);

        $this->recorded[] = new VectorStoreRecord('addDocuments', documents: $documents);

        return $this;
    }

    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);
        $evaluator = new FilterEvaluator();

        $this->documents = array_values(array_filter(
            $this->documents,
            fn (Document $doc): bool => !$evaluator->matchesDocument($filters, $doc)
        ));

        $this->recorded[] = new VectorStoreRecord('delete', filters: $filters);

        return $this;
    }

    /**
     * The preset results are returned regardless of the embedding, trimmed to
     * the request's topK.
     *
     * @return Document[]
     */
    public function search(SearchRequest $request): array
    {
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
        }

        $this->recorded[] = new VectorStoreRecord('search', request: $request);

        return array_slice($this->searchResults, 0, $request->topK);
    }

    /**
     * Set or replace the documents that search() will return.
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
     * @return VectorStoreRecord[]
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
        $searches = count($this->searchRequests());

        Assert::assertSame(
            $expected,
            $searches,
            "Expected {$expected} similarity searches, got {$searches}."
        );
    }

    public function assertSearchedWithFilters(FilterExpression $expected): void
    {
        $filters = [];
        foreach ($this->searchRequests() as $request) {
            if ($request->filters instanceof FilterExpression) {
                $filters[] = $request->filters->toArray();
            }
        }

        Assert::assertContains(
            $expected->toArray(),
            $filters,
            'The vector store was not searched with the expected filters.',
        );
    }

    public function assertDeletedWithFilters(FilterExpression $expected): void
    {
        $filters = [];
        foreach ($this->recorded as $record) {
            if ($record->filters instanceof FilterExpression) {
                $filters[] = $record->filters->toArray();
            }
        }

        Assert::assertContains(
            $expected->toArray(),
            $filters,
            'The vector store was not deleted with the expected filters.',
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
            if ($document->getContent() === $content) {
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

    /**
     * @return SearchRequest[]
     */
    protected function searchRequests(): array
    {
        $requests = [];
        foreach ($this->recorded as $record) {
            if ($record->request instanceof SearchRequest) {
                $requests[] = $record->request;
            }
        }

        return $requests;
    }
}
