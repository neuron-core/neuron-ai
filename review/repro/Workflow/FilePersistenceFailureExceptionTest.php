<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Exceptions\PersistenceException;
use NeuronAI\Workflow\Persistence\FilePersistence;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FilePersistenceFailureExceptionTest extends TestCase
{
    protected string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/neuron-file-persistence-failure-' . uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (['blocker', 'store/run.store', 'store'] as $entry) {
            $path = $this->root . '/' . $entry;
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        rmdir($this->root);
    }

    public function test_directory_creation_failure_raises_persistence_exception(): void
    {
        file_put_contents($this->root . '/blocker', 'not a directory');
        $persistence = new FilePersistence($this->root . '/blocker/store');

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage("Unable to create directory '{$this->root}/blocker/store'");

        $persistence->initializeIfAbsent('run', 'control', 'v1');
    }

    public function test_partition_replace_failure_raises_persistence_exception(): void
    {
        mkdir($this->root . '/store');
        mkdir($this->root . '/store/run.store');
        $persistence = new FilePersistence($this->root . '/store');

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage("Unable to write partition 'run'");

        $persistence->initializeIfAbsent('run', 'control', 'v1');
    }
}
