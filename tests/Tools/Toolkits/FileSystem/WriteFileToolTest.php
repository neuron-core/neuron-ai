<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use Closure;
use ErrorException;
use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use PHPUnit\Framework\TestCase;

use function chmod;
use function error_reporting;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function mkdir;
use function posix_geteuid;
use function restore_error_handler;
use function set_error_handler;

use const E_ALL;

class WriteFileToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createSandbox('neuron_write');
    }

    protected function tearDown(): void
    {
        @chmod($this->tempDir . '/locked', 0o755);
        $this->removeSandbox($this->tempDir);
    }

    public function test_creates_new_file(): void
    {
        $file = $this->tempDir . '/new.txt';

        $this->assertSame([
            'status' => 'success',
            'operation' => 'write_file',
            'file_path' => $file,
            'bytes_written' => 5,
            'message' => "File '{$file}' written successfully.",
        ], (new WriteFileTool())($file, 'hello'));
        $this->assertSame('hello', file_get_contents($file));
    }

    public function test_overwrites_existing_file_entirely(): void
    {
        $file = $this->tempDir . '/existing.txt';
        file_put_contents($file, 'a much longer old content');

        (new WriteFileTool())($file, 'new');

        $this->assertSame('new', file_get_contents($file));
    }

    public function test_empty_content_truncates_the_file(): void
    {
        $file = $this->tempDir . '/existing.txt';
        file_put_contents($file, 'old');

        $result = (new WriteFileTool())($file, '');

        $this->assertSame(0, $result['bytes_written']);
        $this->assertSame('', file_get_contents($file));
    }

    public function test_bytes_written_counts_bytes_of_multibyte_content(): void
    {
        $result = (new WriteFileTool())($this->tempDir . '/unicode.txt', 'héllo 🌍');

        $this->assertSame(11, $result['bytes_written']);
        $this->assertSame('héllo 🌍', file_get_contents($this->tempDir . '/unicode.txt'));
    }

    public function test_creates_missing_parent_directories(): void
    {
        (new WriteFileTool())($this->tempDir . '/a/b/c/file.txt', 'deep');

        $this->assertSame('deep', file_get_contents($this->tempDir . '/a/b/c/file.txt'));
    }

    public function test_relative_path_under_a_scope_is_echoed_as_given(): void
    {
        $result = (new WriteFileTool($this->tempDir))('docs/readme.md', '# Title');

        $this->assertSame('docs/readme.md', $result['file_path']);
        $this->assertSame("File 'docs/readme.md' written successfully.", $result['message']);
        $this->assertSame('# Title', file_get_contents($this->tempDir . '/docs/readme.md'));
    }

    public function test_writing_through_a_symlink_inside_the_scope_updates_its_target(): void
    {
        file_put_contents($this->tempDir . '/target.txt', 'old');
        $this->symlinkOrSkip($this->tempDir . '/target.txt', $this->tempDir . '/link.txt');

        (new WriteFileTool($this->tempDir))('link.txt', 'new');

        $this->assertSame('new', file_get_contents($this->tempDir . '/target.txt'));
    }

    public function test_returns_error_for_non_writable_directory(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can write in any directory.');
        }

        mkdir($this->tempDir . '/locked');
        chmod($this->tempDir . '/locked', 0o555);

        $this->assertToolError(
            "Directory '{$this->tempDir}/locked' is not writable.",
            (new WriteFileTool())($this->tempDir . '/locked/file.txt', 'content')
        );
        $this->assertFileDoesNotExist($this->tempDir . '/locked/file.txt');
    }

    public function test_writing_over_a_directory_is_a_tool_error(): void
    {
        mkdir($this->tempDir . '/docs');

        $this->assertToolError(
            "'{$this->tempDir}/docs' is a directory, not a file.",
            $this->withWarningsAsExceptions(fn (): mixed => (new WriteFileTool())($this->tempDir . '/docs', 'content'))
        );
    }

    public function test_writing_below_a_file_is_a_tool_error(): void
    {
        file_put_contents($this->tempDir . '/file.txt', 'keep');

        $this->assertToolError(
            "Directory '{$this->tempDir}/file.txt' could not be created.",
            $this->withWarningsAsExceptions(fn (): mixed => (new WriteFileTool())($this->tempDir . '/file.txt/child.txt', 'content'))
        );
        $this->assertSame('keep', file_get_contents($this->tempDir . '/file.txt'));
    }

    public function test_an_unwritable_file_is_a_tool_error(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Root can write any file.');
        }

        file_put_contents($this->tempDir . '/locked.txt', 'keep');
        chmod($this->tempDir . '/locked.txt', 0o444);

        $this->assertToolError(
            "Failed to write file '{$this->tempDir}/locked.txt'.",
            $this->withWarningsAsExceptions(fn (): mixed => (new WriteFileTool())($this->tempDir . '/locked.txt', 'content'))
        );
        $this->assertSame('keep', file_get_contents($this->tempDir . '/locked.txt'));
    }

    public function test_tool_schema(): void
    {
        $tool = new WriteFileTool();

        $this->assertSame('write_file', $tool->getName());
        $this->assertSame(['file_path', 'content'], $tool->getRequiredProperties());
    }

    /**
     * Frameworks such as Laravel and Symfony turn PHP warnings into exceptions, unless silenced with `@`.
     */
    protected function withWarningsAsExceptions(Closure $callback): mixed
    {
        $reporting = error_reporting(E_ALL);
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }
}
