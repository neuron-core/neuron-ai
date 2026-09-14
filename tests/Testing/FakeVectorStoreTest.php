<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Testing\VectorStoreRecord;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

use function array_map;

class FakeVectorStoreTest extends TestCase
{
    public function test_add_document(): void
    {
        $store = new FakeVectorStore();
        $doc = $this->embedded('Hello');

        $store->addDocument($doc);

        $this->assertCount(1, $store->getDocuments());
        $this->assertSame($doc, $store->getDocuments()[0]);
    }

    public function test_add_documents(): void
    {
        $store = new FakeVectorStore();

        $store->addDocuments([
            $this->embedded('First'),
            $this->embedded('Second'),
        ]);

        $this->assertCount(2, $store->getDocuments());
    }

    public function test_add_document_requires_an_embedding(): void
    {
        $store = new FakeVectorStore();

        $this->expectException(VectorStoreException::class);
        $this->expectExceptionMessage('must have an embedding');

        $store->addDocument(new Document('Not embedded'));
    }

    public function test_delete_by_source(): void
    {
        $store = new FakeVectorStore();

        $doc1 = $this->embedded('Keep');
        $doc1->setSourceType('file');
        $doc1->setSourceName('keep.txt');

        $doc2 = $this->embedded('Delete');
        $doc2->setSourceType('file');
        $doc2->setSourceName('delete.txt');

        $store->addDocuments([$doc1, $doc2]);
        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'file'), Filter::eq('sourceName', 'delete.txt')));

        $this->assertCount(1, $store->getDocuments());
        $this->assertSame('Keep', $store->getDocuments()[0]->getContent());
    }

    public function test_delete_by(): void
    {
        $store = new FakeVectorStore();

        $doc1 = $this->embedded('Keep');
        $doc1->setSourceType('file');
        $doc1->setSourceName('keep.txt');

        $doc2 = $this->embedded('Delete');
        $doc2->setSourceType('db');
        $doc2->setSourceName('foo-1');

        $store->addDocuments([$doc1, $doc2]);
        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'db')));

        $this->assertCount(1, $store->getDocuments());
        $this->assertSame('Keep', $store->getDocuments()[0]->getContent());
    }

    public function test_similarity_search_returns_preset_results(): void
    {
        $doc = (new Document('Result'))->setScore(0.95);

        $store = new FakeVectorStore([$doc]);

        $results = $store->search(new SearchRequest([0.1, 0.2, 0.3]));

        $this->assertCount(1, $results);
        $this->assertSame($doc, $results[0]);
    }

    public function test_similarity_search_ignores_embedding(): void
    {
        $doc = new Document('Always returned');

        $store = new FakeVectorStore([$doc]);

        $this->assertSame([$doc], $store->search(new SearchRequest([0.0])));
        $this->assertSame([$doc], $store->search(new SearchRequest([1.0, 2.0, 3.0])));
    }

    public function test_search_trims_results_to_top_k(): void
    {
        $docs = [new Document('A'), new Document('B'), new Document('C')];

        $store = new FakeVectorStore($docs);

        $this->assertSame($docs, $store->search(new SearchRequest([0.1])));
        $this->assertSame([$docs[0], $docs[1]], $store->search(new SearchRequest([0.1], topK: 2)));
    }

    public function test_set_search_results(): void
    {
        $store = new FakeVectorStore();

        $this->assertEmpty($store->search(new SearchRequest([0.1])));

        $doc = new Document('New result');
        $store->setSearchResults([$doc]);

        $this->assertSame([$doc], $store->search(new SearchRequest([0.1])));
    }

    public function test_records_operations(): void
    {
        $store = new FakeVectorStore();
        $first = $this->embedded('A');
        $second = $this->embedded('B');
        $filters = FilterGroup::and(Filter::eq('sourceType', 'file'), Filter::eq('sourceName', 'test.txt'));
        $request = new SearchRequest([0.1]);

        $store->addDocument($first);
        $store->addDocuments([$second]);
        $store->delete($filters);
        $store->search($request);

        $recorded = $store->getRecorded();

        $this->assertSame(
            ['addDocument', 'addDocuments', 'delete', 'search'],
            array_map(fn (VectorStoreRecord $record): string => $record->method, $recorded)
        );
        $this->assertSame([$first], $recorded[0]->documents);
        $this->assertSame([$second], $recorded[1]->documents);
        $this->assertSame($filters, $recorded[2]->filters);
        $this->assertSame($request, $recorded[3]->request);
    }

    public function test_assert_search_count(): void
    {
        $store = new FakeVectorStore();

        $store->search(new SearchRequest([0.1]));
        $store->search(new SearchRequest([0.2]));

        $store->assertSearchCount(2);
        $this->addToAssertionCount(1);
    }

    public function test_assert_search_count_fails(): void
    {
        $store = new FakeVectorStore();

        $this->expectException(AssertionFailedError::class);
        $store->assertSearchCount(1);
    }

    public function test_assert_searched_with_filters(): void
    {
        $store = new FakeVectorStore();
        $filters = FilterGroup::anyOf(Filter::eq('sourceType', 'file'), Filter::eq('sourceType', 'web'));

        $store->search(new SearchRequest([0.1], $filters));

        $store->assertSearchedWithFilters($filters);
        $this->addToAssertionCount(1);
    }

    public function test_assert_deleted_with_filters(): void
    {
        $store = new FakeVectorStore();
        $filters = Filter::eq('sourceType', 'file');

        $store->delete($filters);

        $store->assertDeletedWithFilters($filters);
        $this->addToAssertionCount(1);
    }

    public function test_assert_document_count(): void
    {
        $store = new FakeVectorStore();
        $store->addDocuments([$this->embedded('A'), $this->embedded('B')]);

        $store->assertDocumentCount(2);
        $this->addToAssertionCount(1);
    }

    public function test_assert_document_count_fails(): void
    {
        $store = new FakeVectorStore();

        $this->expectException(AssertionFailedError::class);
        $store->assertDocumentCount(1);
    }

    public function test_assert_has_document_with_content(): void
    {
        $store = new FakeVectorStore();
        $store->addDocument($this->embedded('Expected content'));

        $store->assertHasDocumentWithContent('Expected content');
        $this->addToAssertionCount(1);
    }

    public function test_assert_has_document_with_content_fails(): void
    {
        $store = new FakeVectorStore();

        $this->expectException(AssertionFailedError::class);
        $store->assertHasDocumentWithContent('Missing');
    }

    public function test_assert_nothing_stored(): void
    {
        $store = new FakeVectorStore();

        $store->assertNothingStored();
        $this->addToAssertionCount(1);
    }

    public function test_assert_nothing_stored_fails(): void
    {
        $store = new FakeVectorStore();
        $store->addDocument($this->embedded('Something'));

        $this->expectException(AssertionFailedError::class);
        $store->assertNothingStored();
    }

    public function test_static_make(): void
    {
        $doc = new Document('Result');
        $store = FakeVectorStore::make([$doc]);

        $this->assertSame([$doc], $store->search(new SearchRequest([0.1])));
    }

    protected function embedded(string $content): Document
    {
        return (new Document($content))->setEmbedding([0.1, 0.2]);
    }
}
