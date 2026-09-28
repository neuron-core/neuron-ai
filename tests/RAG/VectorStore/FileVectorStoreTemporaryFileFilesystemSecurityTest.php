<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function chmod;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_link;
use function mkdir;
use function umask;

use const PHP_OS_FAMILY;

class FileVectorStoreTemporaryFileFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $base;

    protected function setUp(): void
    {
        $this->base = $this->createSandbox('neuron_vector_tmp');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->base);
    }

    public function test_deleting_from_a_store_never_touches_a_sibling_store(): void
    {
        $docs = new FileVectorStore($this->base, name: 'docs');
        $drafts = new FileVectorStore($this->base, name: 'docs_tmp');
        $drafts->addDocument((new Document('draft'))->setEmbedding([1.0, 0.0]));
        $docs->addDocument((new Document('published'))->setEmbedding([1.0, 0.0]));

        $docs->delete(Filter::eq('sourceName', 'nothing-matches'));

        $this->assertFileExists($this->base . '/docs_tmp.store');
        $this->assertSame('draft', $drafts->search(new SearchRequest([1.0, 0.0]))[0]->getContent());
        $this->assertSame('published', $docs->search(new SearchRequest([1.0, 0.0]))[0]->getContent());
    }

    public function test_delete_never_writes_through_a_planted_symlink(): void
    {
        $directory = $this->base . '/store';
        mkdir($directory);
        $outside = $this->base . '/outside.txt';
        file_put_contents($outside, 'important');
        $this->symlinkOrSkip($outside, $directory . '/neuron_tmp.store');

        $store = new FileVectorStore($directory);
        $store->addDocument((new Document('kept'))->setEmbedding([1.0, 0.0]));
        $store->delete(Filter::eq('sourceName', 'nothing-matches'));

        $this->assertSame('important', file_get_contents($outside));
        $this->assertFalse(is_link($directory . '/neuron.store'));
        $this->assertSame('kept', $store->search(new SearchRequest([1.0, 0.0]))[0]->getContent());
    }

    public function test_delete_keeps_the_permissions_of_the_store_file(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions are not available on Windows.');
        }

        // The common default umask, under which a plain new file would be 0644.
        $umask = umask(0o022);

        try {
            // A store shared through its group, e.g. written by a CLI job and searched by the web server.
            $store = new FileVectorStore($this->base);
            $store->addDocument((new Document('kept'))->setEmbedding([1.0, 0.0]));
            chmod($this->base . '/neuron.store', 0o660);

            $store->delete(Filter::eq('sourceName', 'nothing-matches'));
        } finally {
            umask($umask);
        }

        clearstatcache();
        $this->assertSame(0o660, fileperms($this->base . '/neuron.store') & 0o777);
    }
}
