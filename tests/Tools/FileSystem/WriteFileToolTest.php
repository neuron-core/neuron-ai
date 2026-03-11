<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class WriteFileToolTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/synapse_write_test_' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function testCreatesNewFile(): void
    {
        $tool = new WriteFileTool();
        $result = ($tool)($this->tempFile, 'hello');

        $this->assertSame('success', $result['status']);
        $this->assertSame('write_file', $result['operation']);
        $this->assertSame($this->tempFile, $result['file_path']);
        $this->assertSame('hello', file_get_contents($this->tempFile));
    }

    public function testOverwritesExistingFile(): void
    {
        file_put_contents($this->tempFile, 'old content');

        $tool = new WriteFileTool();
        ($tool)($this->tempFile, 'new content');

        $this->assertSame('new content', file_get_contents($this->tempFile));
    }

    public function testReturnsErrorForNonWritableLocation(): void
    {
        $tool = new WriteFileTool();
        $result = ($tool)('/root/cannot_write_here.txt', 'content');

        $this->assertSame('error', $result['status']);
    }

    public function testToolName(): void
    {
        $this->assertSame('write_file', (new WriteFileTool())->getName());
    }
}
