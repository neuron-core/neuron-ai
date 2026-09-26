<?php

declare(strict_types=1);

namespace NeuronAI\Tests\RAG\VectorStore\Repro;

use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use PHPUnit\Framework\TestCase;

use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileVectorStoreNameReproTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/' . uniqid('neuron_file_name_', true);
        mkdir($this->root . '/stores', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/escaped.store', $this->root . '/stores/neuron.store'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->root . '/stores')) {
            rmdir($this->root . '/stores');
        }
        rmdir($this->root);
    }

    public function test_store_name_cannot_escape_the_configured_directory(): void
    {
        try {
            new FileVectorStore($this->root . '/stores', name: '../escaped');
            $this->fail('A store name with path segments must be refused.');
        } catch (VectorStoreException) {
        }

        $this->assertFileDoesNotExist($this->root . '/escaped.store');
    }
}
