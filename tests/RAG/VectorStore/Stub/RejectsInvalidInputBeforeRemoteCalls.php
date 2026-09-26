<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Stub;

use Closure;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Contract shared by every remote vector store: invalid filters and documents
 * are refused locally, so they never reach (or partially reach) the backend.
 */
trait RejectsInvalidInputBeforeRemoteCalls
{
    /**
     * A ready store using the default document schema.
     */
    abstract protected function storeRejectingInvalidInput(): VectorStoreInterface;

    abstract protected function remoteCallCount(): int;

    /**
     * @return array<string, array{Closure(VectorStoreInterface): mixed, class-string<Throwable>, string}>
     */
    public static function invalidStoreInput(): array
    {
        $undeclaredField = 'Filter field "owner" is not declared in the vector store document schema.';
        $missingEmbedding = 'Document no-vector must have an embedding before it can be stored.';

        return [
            'search filtering on an undeclared field' => [
                static fn (VectorStoreInterface $store): mixed => $store->search(new SearchRequest([1.0, 0.0], Filter::eq('owner', 'mallory'))),
                DocumentSchemaException::class,
                $undeclaredField,
            ],
            'delete filtering on an undeclared field' => [
                static fn (VectorStoreInterface $store): mixed => $store->delete(Filter::eq('owner', 'mallory')),
                DocumentSchemaException::class,
                $undeclaredField,
            ],
            'delete with an undeclared field hidden in a disjunction' => [
                static fn (VectorStoreInterface $store): mixed => $store->delete(FilterGroup::anyOf(
                    Filter::eq('sourceType', 'file'),
                    Filter::eq('owner', 'mallory'),
                )),
                DocumentSchemaException::class,
                $undeclaredField,
            ],
            'single document without embedding' => [
                static fn (VectorStoreInterface $store): mixed => $store->addDocument((new Document('No vector'))->setId('no-vector')),
                VectorStoreException::class,
                $missingEmbedding,
            ],
            'batch whose last document has no embedding' => [
                static fn (VectorStoreInterface $store): mixed => $store->addDocuments([
                    (new Document('Valid'))->setEmbedding([1.0, 0.0]),
                    (new Document('No vector'))->setId('no-vector'),
                ]),
                VectorStoreException::class,
                $missingEmbedding,
            ],
        ];
    }

    /**
     * @param Closure(VectorStoreInterface): mixed $operation
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('invalidStoreInput')]
    public function test_invalid_input_is_rejected_before_any_remote_call(Closure $operation, string $exception, string $message): void
    {
        $store = $this->storeRejectingInvalidInput();
        $callsBefore = $this->remoteCallCount();

        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        try {
            $operation($store);
        } finally {
            $this->assertSame($callsBefore, $this->remoteCallCount());
        }
    }
}
