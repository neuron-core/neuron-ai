<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function glob;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileVectorStoreEmptyBatchReproTest extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/' . uniqid('neuron_file_repro_', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_adding_an_empty_batch_does_not_touch_the_store_file(): void
    {
        $store = new FileVectorStore($this->directory);
        $store->addDocuments([]);

        $this->assertSame('', file_get_contents($this->directory . '/neuron.store'));
    }

    public function test_adding_an_empty_batch_keeps_the_store_searchable(): void
    {
        $store = new FileVectorStore($this->directory);
        $store->addDocuments([]);
        $store->addDocument((new Document('kept'))->setEmbedding([1, 0]));

        $results = $store->search(new SearchRequest([1, 0]));

        $this->assertCount(1, $results);
        $this->assertSame('kept', $results[0]->getContent());
    }

    public function test_adding_an_empty_batch_keeps_filtered_search_working(): void
    {
        $store = new FileVectorStore($this->directory);
        $store->addDocuments([]);
        $store->addDocument((new Document('kept'))->setEmbedding([1, 0]));

        $results = $store->search(new SearchRequest([1, 0], filters: Filter::eq('sourceType', 'manual')));

        $this->assertCount(1, $results);
    }

    public function test_delete_after_an_empty_batch_removes_only_matching_documents(): void
    {
        $store = new FileVectorStore($this->directory);
        $store->addDocuments([]);
        $store->addDocument((new Document('kept'))->setEmbedding([1, 0]));

        $store->delete(Filter::eq('sourceType', 'other'));

        $this->assertCount(1, $store->search(new SearchRequest([1, 0])));
    }
}
