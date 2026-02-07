<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Testing;

use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeVectorStore;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;

class FakeVectorStoreTest extends TestCase
{
    public function test_add_document(): void
    {
        $store = new FakeVectorStore();
        $doc = new Document('Hello');

        $store->addDocument($doc);

        $this->assertCount(1, $store->getDocuments());
        $this->assertSame($doc, $store->getDocuments()[0]);
    }

    public function test_add_documents(): void
    {
        $store = new FakeVectorStore();

        $store->addDocuments([
            new Document('First'),
            new Document('Second'),
        ]);

        $this->assertCount(2, $store->getDocuments());
    }

    public function test_delete_by_source(): void
    {
        $store = new FakeVectorStore();

        $doc1 = new Document('Keep');
        $doc1->sourceType = 'file';
        $doc1->sourceName = 'keep.txt';

        $doc2 = new Document('Delete');
        $doc2->sourceType = 'file';
        $doc2->sourceName = 'delete.txt';

        $store->addDocuments([$doc1, $doc2]);
        $store->deleteBySource('file', 'delete.txt');

        $this->assertCount(1, $store->getDocuments());
        $this->assertSame('Keep', $store->getDocuments()[0]->content);
    }

    public function test_similarity_search_returns_preset_results(): void
    {
        $doc = (new Document('Result'))->setScore(0.95);

        $store = new FakeVectorStore([$doc]);

        $results = $store->similaritySearch([0.1, 0.2, 0.3]);

        $this->assertCount(1, $results);
        $this->assertSame($doc, $results[0]);
    }

    public function test_similarity_search_ignores_embedding(): void
    {
        $doc = new Document('Always returned');

        $store = new FakeVectorStore([$doc]);

        $this->assertSame([$doc], $store->similaritySearch([0.0]));
        $this->assertSame([$doc], $store->similaritySearch([1.0, 2.0, 3.0]));
    }

    public function test_set_search_results(): void
    {
        $store = new FakeVectorStore();

        $this->assertEmpty($store->similaritySearch([0.1]));

        $doc = new Document('New result');
        $store->setSearchResults([$doc]);

        $this->assertSame([$doc], $store->similaritySearch([0.1]));
    }

    public function test_records_operations(): void
    {
        $store = new FakeVectorStore();

        $store->addDocument(new Document('A'));
        $store->addDocuments([new Document('B')]);
        $store->deleteBySource('file', 'test.txt');
        $store->similaritySearch([0.1]);

        $recorded = $store->getRecorded();

        $this->assertCount(4, $recorded);
        $this->assertSame('addDocument', $recorded[0]['method']);
        $this->assertSame('addDocuments', $recorded[1]['method']);
        $this->assertSame('deleteBySource', $recorded[2]['method']);
        $this->assertSame('similaritySearch', $recorded[3]['method']);
    }

    public function test_assert_search_count(): void
    {
        $store = new FakeVectorStore();

        $store->similaritySearch([0.1]);
        $store->similaritySearch([0.2]);

        $store->assertSearchCount(2);
        $this->addToAssertionCount(1);
    }

    public function test_assert_search_count_fails(): void
    {
        $store = new FakeVectorStore();

        $this->expectException(AssertionFailedError::class);
        $store->assertSearchCount(1);
    }

    public function test_assert_document_count(): void
    {
        $store = new FakeVectorStore();
        $store->addDocuments([new Document('A'), new Document('B')]);

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
        $store->addDocument(new Document('Expected content'));

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
        $store->addDocument(new Document('Something'));

        $this->expectException(AssertionFailedError::class);
        $store->assertNothingStored();
    }

    public function test_static_make(): void
    {
        $doc = new Document('Result');
        $store = FakeVectorStore::make([$doc]);

        $this->assertSame([$doc], $store->similaritySearch([0.1]));
    }
}
