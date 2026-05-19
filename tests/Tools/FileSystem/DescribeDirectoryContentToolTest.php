<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\DescribeDirectoryContentTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function is_dir;
use function scandir;

use const DIRECTORY_SEPARATOR;

class DescribeDirectoryContentToolTest extends TestCase
{
    private DescribeDirectoryContentTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new DescribeDirectoryContentTool();

        $this->tempDir = tempnam(sys_get_temp_dir(), 'neuron_test_');
        unlink($this->tempDir);
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir($this->tempDir);
    }

    private function cleanupTempDir(string $directory): void
    {
        $items = @scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.') {
                continue;
            }
            if ($item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->cleanupTempDir($path);
            } else {
                unlink($path);
            }
        }

        if ($directory !== $this->tempDir) {
            rmdir($directory);
        } else {
            @rmdir($directory);
        }
    }

    public function testDescribeNonExistentDirectory(): void
    {
        $result = ($this->tool)('/non/existent/directory');

        $this->assertStringStartsWith("Error: Directory '/non/existent/directory' does not exist.", $result);
    }

    public function testDescribeEmptyDirectory(): void
    {
        $result = ($this->tool)($this->tempDir);

        $this->assertStringContainsString("is empty.", $result);
        $this->assertStringContainsString($this->tempDir, $result);
    }

    public function testDescribeDirectoryWithFiles(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt', 'content 1');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file2.txt', 'content 2');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file3.log', 'log content');

        $result = ($this->tool)($this->tempDir);

        $this->assertStringContainsString('file1.txt', $result);
        $this->assertStringContainsString('file2.txt', $result);
        $this->assertStringContainsString('file3.log', $result);
        $this->assertStringContainsString('Files (3):', $result);
    }

    public function testDescribeDirectoryWithSubdirectories(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'dir1');
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'dir2');

        $result = ($this->tool)($this->tempDir);

        $this->assertStringContainsString('dir1', $result);
        $this->assertStringContainsString('dir2', $result);
        $this->assertStringContainsString('Directories (2):', $result);
    }

    public function testDescribeDirectoryWithMixedContent(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'subdir1');
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'subdir2');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt', 'content');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file2.md', 'markdown');

        $result = ($this->tool)($this->tempDir);

        $this->assertStringContainsString('Directories (2):', $result);
        $this->assertStringContainsString('Files (2):', $result);
        $this->assertStringContainsString('subdir1', $result);
        $this->assertStringContainsString('subdir2', $result);
        $this->assertStringContainsString('file1.txt', $result);
        $this->assertStringContainsString('file2.md', $result);
    }

    public function testDescribeDirectoryWithNestedContent(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested';
        mkdir($subDir);
        file_put_contents($subDir . DIRECTORY_SEPARATOR . 'nested_file.txt', 'nested content');

        $result = ($this->tool)($this->tempDir);

        // Should only show immediate children, not nested
        $this->assertStringContainsString('nested', $result);
        $this->assertStringNotContainsString('nested_file.txt', $result);
    }

    public function testDescribeDirectoryOutputFormat(): void
    {
        mkdir($this->tempDir . DIRECTORY_SEPARATOR . 'mydir');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'myfile.txt', 'content');

        $result = ($this->tool)($this->tempDir);

        $this->assertStringStartsWith("Directory: {$this->tempDir}", $result);
        $this->assertStringContainsString('Directories (1):', $result);
        $this->assertStringContainsString('Files (1):', $result);
        $this->assertMatchesRegularExpression('/  - mydir\n/', $result);
        $this->assertMatchesRegularExpression('/  - myfile\.txt\n/', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('describe_directory_content', $this->tool->getName());
        $this->assertEquals('Describe the contents of a directory. Lists all files and subdirectories in the given directory path.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(1, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('directory', $propertyNames);
    }
}
