<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_link;
use function mkdir;
use function realpath;
use function rmdir;
use function scandir;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileSystemToolTest extends TestCase
{
    use ToolErrorAssertions;

    private string $base;

    private string $scope;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/neuron_scope_' . uniqid();
        $this->scope = $this->base . '/scope';

        mkdir($this->scope . '/sub', 0o755, true);
        mkdir($this->base . '/scope-sibling');
        file_put_contents($this->scope . '/notes.txt', 'inside');
        file_put_contents($this->scope . '/sub/inner.txt', 'nested');
        file_put_contents($this->base . '/outside.txt', 'outside');
        file_put_contents($this->base . '/scope-sibling/leak.txt', 'leak');
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function test_relative_path_resolves_from_the_scope_root(): void
    {
        $this->assertStringStartsWith('inside', (ReadFileTool::make($this->scope))('notes.txt'));
    }

    public function test_relative_path_reaches_subdirectories(): void
    {
        $this->assertStringStartsWith('nested', (ReadFileTool::make($this->scope))('sub/inner.txt'));
    }

    public function test_absolute_path_inside_the_scope_is_allowed(): void
    {
        $this->assertStringStartsWith('inside', (ReadFileTool::make($this->scope))($this->scope . '/notes.txt'));
    }

    public function test_absolute_path_outside_the_scope_is_refused(): void
    {
        $outside = $this->base . '/outside.txt';

        $this->assertToolError(
            "Access denied: '{$outside}' is outside the working scope '" . realpath($this->scope) . "'.",
            (ReadFileTool::make($this->scope))($outside)
        );
    }

    public function test_parent_traversal_is_refused(): void
    {
        $this->assertAccessDenied((ReadFileTool::make($this->scope))('../outside.txt'));
    }

    public function test_parent_traversal_that_stays_inside_is_allowed(): void
    {
        $this->assertStringStartsWith('inside', (ReadFileTool::make($this->scope))('sub/../notes.txt'));
    }

    public function test_sibling_directory_sharing_the_prefix_is_refused(): void
    {
        $this->assertAccessDenied((ReadFileTool::make($this->scope))($this->base . '/scope-sibling/leak.txt'));
    }

    public function test_symlink_pointing_outside_is_refused(): void
    {
        $this->requireSymlink($this->base . '/outside.txt', $this->scope . '/link.txt');

        $this->assertAccessDenied((ReadFileTool::make($this->scope))('link.txt'));
    }

    public function test_dangling_symlink_is_refused(): void
    {
        $this->requireSymlink($this->base . '/missing.txt', $this->scope . '/dangling.txt');

        $this->assertAccessDenied((WriteFileTool::make($this->scope))('dangling.txt', 'x'));
        $this->assertFileDoesNotExist($this->base . '/missing.txt');
    }

    public function test_glob_drops_matches_reached_through_a_symlink(): void
    {
        $this->requireSymlink($this->base . '/scope-sibling', $this->scope . '/linked');

        $result = (GlobPathTool::make($this->scope))('.', '**/*.txt');

        $this->assertStringContainsString('notes.txt', $result);
        $this->assertStringNotContainsString('leak.txt', $result);
    }

    public function test_new_nested_path_inside_the_scope_can_be_written(): void
    {
        $result = (WriteFileTool::make($this->scope))('new/dir/file.txt', 'created');

        $this->assertSame('success', $result['status']);
        $this->assertSame('created', file_get_contents($this->scope . '/new/dir/file.txt'));
    }

    public function test_new_path_outside_the_scope_is_refused(): void
    {
        $this->assertAccessDenied((WriteFileTool::make($this->scope))('../new.txt', 'x'));
        $this->assertFileDoesNotExist($this->base . '/new.txt');
    }

    public function test_new_path_escaping_through_a_missing_directory_is_refused(): void
    {
        $this->assertAccessDenied((WriteFileTool::make($this->scope))('new/../../new.txt', 'x'));
        $this->assertFileDoesNotExist($this->base . '/new.txt');
    }

    public function test_scope_root_itself_is_allowed(): void
    {
        $this->assertStringContainsString('notes.txt', (GlobPathTool::make($this->scope))('.', '*.txt'));
    }

    public function test_scope_must_be_an_existing_directory(): void
    {
        $this->expectException(ToolException::class);

        ReadFileTool::make($this->base . '/missing');
    }

    public function test_without_scope_paths_are_untouched(): void
    {
        $missing = 'neuron_missing_' . uniqid() . '.txt';

        $this->assertStringStartsWith('inside', (ReadFileTool::make())($this->scope . '/notes.txt'));
        $this->assertToolError("File '{$missing}' does not exist.", (ReadFileTool::make())($missing));
    }

    private function assertAccessDenied(mixed $result): void
    {
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Access denied:', $result->getText());
    }

    private function requireSymlink(string $target, string $link): void
    {
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('Symlinks cannot be created on this platform.');
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path)) {
                rmdir($path);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path . '/' . $item);
            }
        }

        rmdir($path);
    }
}
