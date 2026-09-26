<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function scandir;
use function str_replace;

/**
 * PHP opens `scheme://` paths through stream wrappers, so a scoped tool that
 * handed a model-supplied path to the filesystem functions untouched would
 * let `php://filter/resource=/etc/passwd` read, and write, outside its scope.
 */
class StreamWrapperFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected string $base;

    protected string $scope;

    protected string $secret;

    protected function setUp(): void
    {
        $this->base = $this->createSandbox('neuron_wrapper_scope');
        $this->scope = $this->base . '/scope';
        $this->secret = $this->base . '/secret.html';

        mkdir($this->scope);
        file_put_contents($this->secret, 'outside secret');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->base);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrapperPaths(): iterable
    {
        yield 'php filter' => ['php://filter/resource={secret}'];
        yield 'compression wrapper' => ['compress.zlib://{secret}'];
        yield 'file url with a host' => ['file://localhost{secret}'];
    }

    #[DataProvider('wrapperPaths')]
    public function test_readers_never_open_a_wrapper_path_outside_the_scope(string $path): void
    {
        $path = $this->expand($path);

        foreach ([
            ReadFileTool::make($this->scope)($path),
            GrepFileContentTool::make($this->scope)($path, '/secret/i'),
            ParseFileTool::make($this->scope)($path),
            EditFileTool::make($this->scope)($path, 'secret', 'changed'),
        ] as $result) {
            $this->assertToolError("File '{$path}' does not exist.", $result);
        }

        $this->assertSame('outside secret', file_get_contents($this->secret));
    }

    #[DataProvider('wrapperPaths')]
    public function test_delete_never_reaches_a_file_outside_the_scope_through_a_wrapper(string $path): void
    {
        $path = $this->expand($path);

        $this->assertToolError("File '{$path}' does not exist.", DeleteFileTool::make($this->scope)($path));
        $this->assertFileExists($this->secret);
    }

    #[DataProvider('wrapperPaths')]
    public function test_a_write_through_a_wrapper_path_stays_inside_the_scope(string $path): void
    {
        $path = $this->expand($path);

        WriteFileTool::make($this->scope)($path, 'overwritten');

        $this->assertSame('outside secret', file_get_contents($this->secret));
        $this->assertSame(['scope', 'secret.html'], $this->entries($this->base));
    }

    protected function expand(string $path): string
    {
        return str_replace('{secret}', $this->secret, $path);
    }

    /**
     * @return string[]
     */
    protected function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }
}
