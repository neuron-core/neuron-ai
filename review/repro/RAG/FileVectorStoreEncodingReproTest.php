<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;

use function glob;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileVectorStoreEncodingReproTest extends TestCase
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

    public function test_content_that_cannot_be_encoded_is_rejected_and_the_store_stays_searchable(): void
    {
        $store = new FileVectorStore($this->directory);

        try {
            $store->addDocument((new Document("broken \xB1 utf-8"))->setEmbedding([1, 0]));
            $this->fail('A document whose content cannot be JSON encoded must be rejected.');
        } catch (VectorStoreException) {
        }

        $store->addDocument((new Document('kept'))->setEmbedding([1, 0]));

        $results = $store->search(new SearchRequest([1, 0]));
        $this->assertCount(1, $results);
        $this->assertSame('kept', $results[0]->getContent());
    }

    public function test_source_name_that_cannot_be_encoded_is_rejected(): void
    {
        $store = new FileVectorStore($this->directory);
        $document = (new Document('content'))->setEmbedding([1, 0])->setSourceName("file-\xC3\x28.txt");

        $this->expectException(VectorStoreException::class);

        $store->addDocument($document);
    }
}
