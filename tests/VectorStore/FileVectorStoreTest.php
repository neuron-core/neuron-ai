<?php

declare(strict_types=1);

namespace NeuronAI\Tests\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use PHPUnit\Framework\TestCase;

use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use function uniqid;

class FileVectorStoreTest extends TestCase
{
    public function test_store_documents(): void
    {
        $document = new Document('Hello!');
        $document->addMetadata('customProperty', 'customValue');
        $document->embedding = [1, 2, 3];
        $document->id = 1;
        $document->sourceName = 'test';
        $document->sourceType = 'string';

        $document2 = new Document('Hello 2!');
        $document2->embedding = [3, 4, 5];
        $document2->id = 2;
        $document2->sourceName = 'test';
        $document2->sourceType = 'string';

        $store = new FileVectorStore(__DIR__, 1);
        $store->addDocuments([$document, $document2]);

        $results = $store->similaritySearch([1, 2, 3]);

        $this->assertCount(1, $results);
        $this->assertEquals($document->id, $results[0]->getId());
        $this->assertEquals($document->content, $results[0]->getContent());
        $this->assertEquals($document->embedding, $results[0]->getEmbedding());
        $this->assertEquals($document->sourceType, $results[0]->getSourceType());
        $this->assertEquals($document->sourceName, $results[0]->getSourceName());
        $this->assertEquals($document->metadata, $results[0]->metadata);

        unlink(__DIR__.'/neuron.store');
        $this->assertFileDoesNotExist(__DIR__.'/neuron.store');
    }

    public function test_delete_documents(): void
    {
        $document = new Document('Hello!');
        $document->embedding = [1, 2, 3];

        $document2 = new Document('Hello 2!');
        $document2->embedding = [3, 4, 5];

        $store = new FileVectorStore(__DIR__);

        $store->addDocuments([$document, $document2]);
        $store->deleteBySource('manual', 'manual');

        $results = $store->similaritySearch([1, 2, 3]);
        $this->assertCount(0, $results);

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
