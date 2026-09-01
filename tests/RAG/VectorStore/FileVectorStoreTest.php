<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

use function unlink;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function file_get_contents;
use function json_decode;

class FileVectorStoreTest extends TestCase
{
    public function test_store_documents(): void
    {
        $document = new Document('Hello!');
        $document->addMetadata('customProperty', 'customValue');
        $document->setEmbedding([1, 2, 3]);
        $document->setId(1);
        $document->setSourceName('test');
        $document->setSourceType('string');

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([3, 4, 5]);
        $document2->setId(2);
        $document2->setSourceName('test');
        $document2->setSourceType('string');

        $store = new FileVectorStore(__DIR__, 1);
        $store->addDocuments([$document, $document2]);

        $results = $store->search(new SearchRequest([1, 2, 3]));

        $this->assertCount(1, $results);
        $this->assertEquals($document->getId(), $results[0]->getId());
        $this->assertEquals($document->getContent(), $results[0]->getContent());
        $this->assertEquals($document->getEmbedding(), $results[0]->getEmbedding());
        $this->assertEquals($document->getSourceType(), $results[0]->getSourceType());
        $this->assertEquals($document->getSourceName(), $results[0]->getSourceName());
        $this->assertEquals($document->getMetadata(), $results[0]->getMetadata());

        unlink(__DIR__.'/neuron.store');
        $this->assertFileDoesNotExist(__DIR__.'/neuron.store');
    }

    public function test_does_not_persist_runtime_score(): void
    {
        $document = (new Document('Hello!'))
            ->setEmbedding([1, 2, 3])
            ->setScore(0.9);
        $store = new FileVectorStore(__DIR__);

        $store->addDocument($document);

        $stored = json_decode((string) file_get_contents(__DIR__.'/neuron.store'), true);
        $this->assertArrayNotHasKey('score', $stored);

        unlink(__DIR__.'/neuron.store');
    }

    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->setEmbedding([1, 2, 3]);

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([3, 4, 5]);

        $store = new FileVectorStore(__DIR__);

        $store->addDocuments([$document, $document2]);
        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'manual'), Filter::eq('sourceName', 'manual')));

        $results = $store->search(new SearchRequest([1, 2, 3]));
        $this->assertCount(0, $results);

        unlink(__DIR__.'/neuron.store');
        $this->assertFileDoesNotExist(__DIR__.'/neuron.store');
        $this->assertFileDoesNotExist(__DIR__.'/neuron_tmp.store');
    }

    public function test_delete_by_type(): void
    {
        $document1 = new Document('Hello!');
        $document1->setEmbedding([1, 2, 3]);
        $document1->setSourceType('web');
        $document1->setSourceName('page-a');

        $document2 = new Document('Hello 2!');
        $document2->setEmbedding([3, 4, 5]);
        $document2->setSourceType('web');
        $document2->setSourceName('page-b');

        $document3 = new Document('Hello 3!');
        $document3->setEmbedding([2, 2, 2]);
        $document3->setSourceType('file');
        $document3->setSourceName('doc.txt');

        $store = new FileVectorStore(__DIR__, 3);
        $store->addDocuments([$document1, $document2, $document3]);

        $store->delete(FilterGroup::and(Filter::eq('sourceType', 'web')));

        $results = $store->search(new SearchRequest([1, 2, 3]));
        $this->assertCount(1, $results);
        $this->assertEquals('file', $results[0]->getSourceType());

        unlink(__DIR__.'/neuron.store');
        $this->assertFileDoesNotExist(__DIR__.'/neuron.store');
        $this->assertFileDoesNotExist(__DIR__.'/neuron_tmp.store');
    }

    public function test_creates_directory_if_not_exists(): void
    {
        $testDir = sys_get_temp_dir() . '/neuron_test_' . uniqid();

        // Ensure directory doesn't exist
        $this->assertDirectoryDoesNotExist($testDir);

        // Create store with non-existent directory
        new FileVectorStore($testDir);

        // Verify directory was created
        $this->assertDirectoryExists($testDir);

        // Cleanup
        rmdir($testDir);
    }
}
