<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function str_repeat;
use function uniqid;

use const DIRECTORY_SEPARATOR;

class FileSystemToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected string $base;

    protected string $scope;

    protected function setUp(): void
    {
        $this->base = $this->createSandbox('neuron_scope');
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
        $this->removeSandbox($this->base);
    }

    public function test_relative_path_resolves_from_the_scope_root(): void
    {
        $this->assertStringStartsWith('inside', (ReadFileTool::make($this->scope))('notes.txt'));
    }

    public function test_relative_path_reaches_subdirectories(): void
    {
        $this->assertStringStartsWith('nested', (ReadFileTool::make($this->scope))('sub/inner.txt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function redundantSegmentsProvider(): iterable
    {
        yield 'dot segments' => ['./sub/./inner.txt'];
        yield 'repeated separators' => ['sub//inner.txt'];
        yield 'trailing parent that stays inside' => ['sub/../sub/inner.txt'];
    }

    #[DataProvider('redundantSegmentsProvider')]
    public function test_redundant_segments_inside_the_scope_are_normalized(string $path): void
    {
        $this->assertStringStartsWith('nested', (ReadFileTool::make($this->scope))($path));
    }

    public function test_absolute_path_inside_the_scope_is_allowed(): void
    {
        $this->assertStringStartsWith('inside', (ReadFileTool::make($this->scope))($this->scope . '/notes.txt'));
    }

    public function test_absolute_path_outside_the_scope_is_refused(): void
    {
        $outside = $this->base . '/outside.txt';

        $this->assertToolError(
            "Access denied: '{$outside}' is outside the working scope '{$this->scope}'.",
            (ReadFileTool::make($this->scope))($outside)
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalProvider(): iterable
    {
        yield 'parent segment' => ['../outside.txt'];
        yield 'parent after a subdirectory' => ['sub/../../outside.txt'];
        yield 'parent after a dot segment' => ['./../outside.txt'];
        yield 'parent with repeated separators' => ['..//outside.txt'];
        yield 'more parents than the path is deep' => [str_repeat('../', 64) . 'etc/passwd'];
        yield 'system file by absolute path' => ['/etc/passwd'];
    }

    #[DataProvider('traversalProvider')]
    public function test_traversal_outside_the_scope_is_refused(string $path): void
    {
        $this->assertAccessDenied((ReadFileTool::make($this->scope))($path));
    }

    public function test_absolute_path_climbing_out_of_the_scope_is_refused(): void
    {
        $this->assertAccessDenied((ReadFileTool::make($this->scope))($this->scope . '/sub/../../outside.txt'));
    }

    public function test_parent_traversal_that_stays_inside_is_allowed(): void
    {
        $this->assertStringStartsWith('inside', (ReadFileTool::make($this->scope))('sub/../notes.txt'));
    }

    public function test_sibling_directory_sharing_the_prefix_is_refused(): void
    {
        $this->assertAccessDenied((ReadFileTool::make($this->scope))($this->base . '/scope-sibling/leak.txt'));
    }

    public function test_sibling_directory_sharing_the_prefix_is_refused_through_traversal(): void
    {
        $this->assertAccessDenied((ReadFileTool::make($this->scope))('../scope-sibling/leak.txt'));
    }

    public function test_symlink_pointing_outside_is_refused(): void
    {
        $this->symlinkOrSkip($this->base . '/outside.txt', $this->scope . '/link.txt');

        $this->assertAccessDenied((ReadFileTool::make($this->scope))('link.txt'));
    }

    public function test_symlink_pointing_inside_is_followed(): void
    {
        $this->symlinkOrSkip($this->scope . '/sub/inner.txt', $this->scope . '/alias.txt');

        $this->assertStringStartsWith('nested', (ReadFileTool::make($this->scope))('alias.txt'));
    }

    public function test_file_below_a_symlinked_directory_pointing_outside_is_refused(): void
    {
        $this->symlinkOrSkip($this->base . '/scope-sibling', $this->scope . '/linked');

        $this->assertAccessDenied((ReadFileTool::make($this->scope))('linked/leak.txt'));
    }

    public function test_new_file_below_a_symlinked_directory_pointing_outside_is_refused(): void
    {
        $this->symlinkOrSkip($this->base . '/scope-sibling', $this->scope . '/linked');

        $this->assertAccessDenied((WriteFileTool::make($this->scope))('linked/planted/file.txt', 'x'));
        $this->assertDirectoryDoesNotExist($this->base . '/scope-sibling/planted');
    }

    public function test_dangling_symlink_is_refused(): void
    {
        $this->symlinkOrSkip($this->base . '/missing.txt', $this->scope . '/dangling.txt');

        $this->assertAccessDenied((WriteFileTool::make($this->scope))('dangling.txt', 'x'));
        $this->assertFileDoesNotExist($this->base . '/missing.txt');
    }

    public function test_glob_drops_matches_reached_through_a_symlink(): void
    {
        $this->symlinkOrSkip($this->base . '/scope-sibling', $this->scope . '/linked');

        $result = (GlobPathTool::make($this->scope))('.', '**/*.txt');

        $this->assertStringContainsString('notes.txt', $result);
        $this->assertStringNotContainsString('leak.txt', $result);
    }

    public function test_null_byte_cannot_truncate_the_path(): void
    {
        $this->assertToolError(
            "File 'notes.txt\0.png' does not exist.",
            (ReadFileTool::make($this->scope))("notes.txt\0.png")
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function literalNameProvider(): iterable
    {
        yield 'percent-encoded parent' => ['%2e%2e/outside.txt'];
        yield 'percent-encoded separator' => ['..%2foutside.txt'];
        yield 'file URL' => ['file://' . '/etc/passwd'];
    }

    #[DataProvider('literalNameProvider')]
    public function test_encoded_paths_are_taken_literally_inside_the_scope(string $path): void
    {
        $this->assertToolError("File '{$path}' does not exist.", (ReadFileTool::make($this->scope))($path));
    }

    public function test_backslash_is_part_of_the_file_name_on_posix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('The backslash is a separator on Windows.');
        }

        $this->assertToolError(
            "File '..\\outside.txt' does not exist.",
            (ReadFileTool::make($this->scope))('..\\outside.txt')
        );
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

    public function test_new_path_escaping_below_missing_directories_does_not_create_them(): void
    {
        $this->assertAccessDenied((WriteFileTool::make($this->scope))('a/b/../../../escaped/file.txt', 'x'));
        $this->assertDirectoryDoesNotExist($this->base . '/escaped');
        $this->assertDirectoryDoesNotExist($this->scope . '/a');
    }

    public function test_scope_root_itself_is_allowed(): void
    {
        $this->assertStringContainsString('notes.txt', (GlobPathTool::make($this->scope))('.', '*.txt'));
    }

    public function test_scope_is_canonicalized(): void
    {
        $outside = $this->base . '/outside.txt';

        $this->assertToolError(
            "Access denied: '{$outside}' is outside the working scope '{$this->scope}'.",
            (ReadFileTool::make($this->scope . '/sub/../'))($outside)
        );
    }

    public function test_scope_given_through_a_symlink_confines_to_its_target(): void
    {
        $this->symlinkOrSkip($this->scope, $this->base . '/scope-link');
        $tool = ReadFileTool::make($this->base . '/scope-link');

        $this->assertStringStartsWith('inside', $tool('notes.txt'));
        $this->assertStringStartsWith('inside', $tool($this->scope . '/notes.txt'));
        $this->assertAccessDenied($tool('../outside.txt'));
    }

    public function test_scope_must_be_an_existing_directory(): void
    {
        $missing = $this->base . '/missing';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage(ReadFileTool::class . " requires an existing directory as scope, '{$missing}' given.");

        new ReadFileTool($missing);
    }

    public function test_scope_cannot_be_a_file(): void
    {
        $file = $this->scope . '/notes.txt';

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage(WriteFileTool::class . " requires an existing directory as scope, '{$file}' given.");

        new WriteFileTool($file);
    }

    public function test_without_scope_paths_are_untouched(): void
    {
        $missing = 'neuron_missing_' . uniqid() . '.txt';

        $this->assertStringStartsWith('inside', (ReadFileTool::make())($this->scope . '/notes.txt'));
        $this->assertToolError("File '{$missing}' does not exist.", (ReadFileTool::make())($missing));
    }

    protected function assertAccessDenied(mixed $result): void
    {
        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith('Access denied:', $result->getText());
        $this->assertStringEndsWith("is outside the working scope '{$this->scope}'.", $result->getText());
    }
}
