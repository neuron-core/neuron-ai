<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Workflow\Persistence;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Workflow\Persistence\FilePersistence;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_get_contents;
use function file_put_contents;
use function is_link;
use function json_encode;
use function mkdir;

use const PHP_OS_FAMILY;

class FilePersistenceFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $base;

    protected string $directory;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Symlinks need elevated privileges on Windows.');
        }

        $this->base = $this->createSandbox('neuron_file_persistence');
        $this->directory = $this->base . '/store';
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->removeSandbox($this->base);
        }
    }

    public function test_delete_removes_a_planted_symlink_but_never_its_target(): void
    {
        $outside = $this->base . '/outside.json';
        $partition = (string) json_encode(['version' => 2, 'records' => [base64_encode('__control') => base64_encode('owner')]]);
        file_put_contents($outside, $partition);
        $this->symlinkOrSkip($outside, $this->directory . '/workflow.store');

        $this->assertTrue((new FilePersistence($this->directory))->deleteIfUnchanged('workflow', '__control', 'owner'));

        $this->assertFalse(is_link($this->directory . '/workflow.store'));
        $this->assertSame($partition, file_get_contents($outside));
    }
}
