<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\DataLoader;

use NeuronAI\RAG\DataLoader\FileDataLoader;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\RAG;
use NeuronAI\Testing\FakeEmbeddingsProvider;
use NeuronAI\Testing\FakeVectorStore;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mkdir;
use function sort;

class FileDataLoaderSourceNameTest extends TestCase
{
    use FileSystemSandbox;

    protected string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = $this->createSandbox('neuron_file_loader_source');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->sandbox);
    }

    public function test_files_with_the_same_name_in_different_folders_have_distinct_source_names(): void
    {
        mkdir($this->sandbox . '/tenant-a');
        mkdir($this->sandbox . '/tenant-b');
        file_put_contents($this->sandbox . '/tenant-a/readme.txt', 'A');
        file_put_contents($this->sandbox . '/tenant-b/readme.txt', 'B');

        $names = array_map(static fn (Document $document): string => $document->getSourceName(), FileDataLoader::for($this->sandbox)->getDocuments());
        sort($names);

        $this->assertCount(2, $names);
        $this->assertNotSame($names[0], $names[1]);
    }

    public function test_a_file_has_the_same_source_name_whether_loaded_alone_or_with_its_directory(): void
    {
        $path = $this->sandbox . '/guide.txt';
        file_put_contents($path, 'Guide');

        $alone = FileDataLoader::for($path)->getDocuments()[0];
        $withDirectory = FileDataLoader::for($this->sandbox)->getDocuments()[0];

        $this->assertSame($alone->getSourceName(), $withDirectory->getSourceName());
    }

    public function test_reindexing_one_folder_keeps_a_same_named_file_of_another_folder(): void
    {
        mkdir($this->sandbox . '/tenant-a');
        mkdir($this->sandbox . '/tenant-b');
        file_put_contents($this->sandbox . '/tenant-a/readme.txt', 'Tenant A');
        file_put_contents($this->sandbox . '/tenant-b/readme.txt', 'Tenant B');
        $store = new FakeVectorStore();
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($store);

        $rag->addDocuments(FileDataLoader::for($this->sandbox . '/tenant-b')->getDocuments());
        $rag->reindexBySource(FileDataLoader::for($this->sandbox . '/tenant-a')->getDocuments());

        $contents = array_map(static fn (Document $document): string => $document->getContent(), $store->getDocuments());
        sort($contents);
        $this->assertSame(['Tenant A', 'Tenant B'], $contents);
    }

    public function test_reindexing_a_file_loaded_alone_replaces_its_chunks_from_a_directory_load(): void
    {
        file_put_contents($this->sandbox . '/guide.txt', 'Old guide');
        $store = new FakeVectorStore();
        $rag = RAG::make()->setEmbeddingsProvider(new FakeEmbeddingsProvider())->setVectorStore($store);
        $rag->addDocuments(FileDataLoader::for($this->sandbox)->getDocuments());

        file_put_contents($this->sandbox . '/guide.txt', 'New guide');
        $rag->reindexBySource(FileDataLoader::for($this->sandbox . '/guide.txt')->getDocuments());

        $contents = array_map(static fn (Document $document): string => $document->getContent(), $store->getDocuments());
        $this->assertSame(['New guide'], $contents);
    }
}
