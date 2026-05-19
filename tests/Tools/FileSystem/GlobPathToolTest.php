<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
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

class GlobPathToolTest extends TestCase
{
    private GlobPathTool $tool;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new GlobPathTool();

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

    public function testGlobNonExistentDirectory(): void
    {
        $result = ($this->tool)('/non/existent/directory', '*.txt');

        $this->assertStringStartsWith("Error: Directory '/non/existent/directory' does not exist.", $result);
    }

    public function testGlobNoMatches(): void
    {
        $result = ($this->tool)($this->tempDir, '*.pdf');

        $this->assertStringContainsString("No matches found for pattern '*.pdf'", $result);
        $this->assertStringContainsString($this->tempDir, $result);
    }

    public function testGlobSimplePattern(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt', 'content1');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file2.txt', 'content2');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file3.log', 'log content');

        $result = ($this->tool)($this->tempDir, '*.txt');

        $this->assertStringContainsString('Found 2 match(es)', $result);
        $this->assertStringContainsString('file1.txt', $result);
        $this->assertStringContainsString('file2.txt', $result);
        $this->assertStringNotContainsString('file3.log', $result);
    }

    public function testGlobAllFiles(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt', 'content1');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file2.php', 'content2');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file3.md', 'content3');

        $result = ($this->tool)($this->tempDir, '*');

        $this->assertStringContainsString('Found 3 match(es)', $result);
        $this->assertStringContainsString('file1.txt', $result);
        $this->assertStringContainsString('file2.php', $result);
        $this->assertStringContainsString('file3.md', $result);
    }

    public function testGlobRecursiveWithSubdirectories(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested';
        mkdir($subDir);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'root.txt', 'root content');
        file_put_contents($subDir . DIRECTORY_SEPARATOR . 'nested.txt', 'nested content');

        $result = ($this->tool)($this->tempDir, '**/*.txt');

        $this->assertStringContainsString('Found 2 match(es)', $result);
        $this->assertStringContainsString('root.txt', $result);
        $this->assertStringContainsString('nested.txt', $result);
    }

    public function testGlobRecursiveDeeplyNested(): void
    {
        $level1 = $this->tempDir . DIRECTORY_SEPARATOR . 'level1';
        $level2 = $level1 . DIRECTORY_SEPARATOR . 'level2';
        mkdir($level1);
        mkdir($level2);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'root.txt', 'root');
        file_put_contents($level1 . DIRECTORY_SEPARATOR . 'l1.txt', 'level1');
        file_put_contents($level2 . DIRECTORY_SEPARATOR . 'l2.txt', 'level2');

        $result = ($this->tool)($this->tempDir, '**/*.txt');

        $this->assertStringContainsString('Found 3 match(es)', $result);
        $this->assertStringContainsString('root.txt', $result);
        $this->assertStringContainsString('l1.txt', $result);
        $this->assertStringContainsString('l2.txt', $result);
    }

    public function testGlobRecursiveMixedExtensions(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested';
        mkdir($subDir);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'root.php', 'php content');
        file_put_contents($subDir . DIRECTORY_SEPARATOR . 'nested.php', 'nested php');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'ignore.txt', 'txt content');

        $result = ($this->tool)($this->tempDir, '**/*.php');

        $this->assertStringContainsString('Found 2 match(es)', $result);
        $this->assertStringContainsString('root.php', $result);
        $this->assertStringContainsString('nested.php', $result);
        $this->assertStringNotContainsString('ignore.txt', $result);
    }

    public function testGlobNonRecursiveDoesNotIncludeNested(): void
    {
        $subDir = $this->tempDir . DIRECTORY_SEPARATOR . 'nested';
        mkdir($subDir);

        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'root.txt', 'root');
        file_put_contents($subDir . DIRECTORY_SEPARATOR . 'nested.txt', 'nested');

        $result = ($this->tool)($this->tempDir, '*.txt');

        $this->assertStringContainsString('Found 1 match(es)', $result);
        $this->assertStringContainsString('root.txt', $result);
        $this->assertStringNotContainsString('nested.txt', $result);
    }

    public function testGlobWithQuestionMarkWildcard(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file1.txt', 'content1');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'file2.txt', 'content2');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'data.txt', 'data');

        $result = ($this->tool)($this->tempDir, 'file?.txt');

        $this->assertStringContainsString('Found 2 match(es)', $result);
        $this->assertStringContainsString('file1.txt', $result);
        $this->assertStringContainsString('file2.txt', $result);
        $this->assertStringNotContainsString('data.txt', $result);
    }

    public function testGlobMultipleExtensions(): void
    {
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'test.txt', 'text');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'test.log', 'log');
        file_put_contents($this->tempDir . DIRECTORY_SEPARATOR . 'other.md', 'markdown');

        $result = ($this->tool)($this->tempDir, 'test.*');

        $this->assertStringContainsString('Found 2 match(es)', $result);
        $this->assertStringContainsString('test.txt', $result);
        $this->assertStringContainsString('test.log', $result);
        $this->assertStringNotContainsString('other.md', $result);
    }

    public function testGlobEmptyDirectory(): void
    {
        $result = ($this->tool)($this->tempDir, '*');

        $this->assertStringContainsString('No matches found', $result);
    }

    public function testToolProperties(): void
    {
        $this->assertEquals('glob_path', $this->tool->getName());
        $this->assertEquals('Find files matching a glob pattern in a directory.', $this->tool->getDescription());

        $properties = $this->tool->getProperties();
        $this->assertCount(2, $properties);

        $propertyNames = array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $properties);
        $this->assertContains('directory', $propertyNames);
        $this->assertContains('pattern', $propertyNames);
    }
}
