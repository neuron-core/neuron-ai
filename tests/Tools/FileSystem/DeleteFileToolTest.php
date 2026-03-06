<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class DeleteFileToolTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/synapse_delete_test_' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testDeletesFile(): void
    {
        file_put_contents($this->tempFile, 'content');

        $tool = new DeleteFileTool();
        $result = ($tool)($this->tempFile);

        $this->assertSame('success', $result['status']);
        $this->assertSame('delete_file', $result['operation']);
        $this->assertFalse(file_exists($this->tempFile));
    }

    public function testReturnsErrorWhenFileDoesNotExist(): void
    {
        $tool = new DeleteFileTool();
        $result = ($tool)('/non/existent/file.txt');

        $this->assertSame('error', $result['status']);
    }

    public function testReturnsErrorWhenPathIsDirectory(): void
    {
        $tool = new DeleteFileTool();
        $result = ($tool)(sys_get_temp_dir());

        $this->assertSame('error', $result['status']);
    }

    public function testToolName(): void
    {
        $this->assertSame('delete_file', (new DeleteFileTool())->getName());
    }
}
