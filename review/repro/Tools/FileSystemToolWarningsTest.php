<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use ErrorException;
use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function restore_error_handler;
use function set_error_handler;

/**
 * Frameworks such as Laravel and Symfony turn PHP warnings into exceptions,
 * so a warning escaping a tool aborts the agent run instead of informing the model.
 */
class FileSystemToolWarningsTest extends TestCase
{
    use FileSystemSandbox;

    protected string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->createSandbox('neuron_fs_warnings');
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $this->removeSandbox($this->dir);
    }

    public function test_writing_over_a_directory_is_a_tool_error(): void
    {
        mkdir($this->dir . '/docs');

        $result = (new WriteFileTool($this->dir))('docs', 'content');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertTrue(is_dir($this->dir . '/docs'));
    }

    public function test_writing_below_a_file_is_a_tool_error(): void
    {
        file_put_contents($this->dir . '/file.txt', 'x');

        $result = (new WriteFileTool($this->dir))('file.txt/child.txt', 'content');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertSame('x', file_get_contents($this->dir . '/file.txt'));
    }
}
