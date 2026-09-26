<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function chmod;
use function file_put_contents;
use function function_exists;
use function mkdir;
use function posix_geteuid;
use function str_repeat;

class ReadFileToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected ReadFileTool $tool;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new ReadFileTool();
        $this->tempDir = $this->createSandbox('neuron_read');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_read_non_existent_file(): void
    {
        $this->assertToolError("File '/non/existent/file.txt' does not exist.", ($this->tool)('/non/existent/file.txt'));
    }

    public function test_directory_is_not_read_as_a_file(): void
    {
        mkdir($this->tempDir . '/dir');

        $this->assertToolError("File 'dir' does not exist.", (new ReadFileTool($this->tempDir))('dir'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function contentProvider(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'single line' => ['Hello, World!', 13];
        yield 'multiline with trailing newline' => ["Line 1\nLine 2\r\nLine 3\n", 22];
        yield 'multibyte characters are counted as characters' => ['Unicode: 你好世界 مرحبا 🌍', 21];
        yield 'large' => [str_repeat('A', 100_000), 100_000];
    }

    #[DataProvider('contentProvider')]
    public function test_returns_the_exact_content_followed_by_its_character_count(string $content, int $characters): void
    {
        file_put_contents($this->tempDir . '/file.txt', $content);

        $this->assertSame(
            $content . "\n\n[File read successfully: {$characters} characters]",
            ($this->tool)($this->tempDir . '/file.txt')
        );
    }

    public function test_binary_content_is_returned_byte_for_byte(): void
    {
        $binary = "\x00\x01\xFF\xFE binary \x00";
        file_put_contents($this->tempDir . '/file.bin', $binary);

        $this->assertStringStartsWith($binary . "\n\n[File read successfully: ", ($this->tool)($this->tempDir . '/file.bin'));
    }

    public function test_unreadable_file_is_reported(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can read any file.');
        }

        file_put_contents($this->tempDir . '/secret.txt', 'secret');
        chmod($this->tempDir . '/secret.txt', 0o000);

        $this->assertToolError("File 'secret.txt' is not readable.", (new ReadFileTool($this->tempDir))('secret.txt'));
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('read_file', $this->tool->getName());
        $this->assertSame('Read the contents of a text file.', $this->tool->getDescription());
        $this->assertSame(
            ['file_path'],
            array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties())
        );
    }
}
