<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function file_put_contents;
use function mkdir;

class GlobPathToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected GlobPathTool $tool;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tool = new GlobPathTool();
        $this->tempDir = $this->createSandbox('neuron_glob');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_glob_non_existent_directory(): void
    {
        $this->assertToolError("Directory '/non/existent/directory' does not exist.", ($this->tool)('/non/existent/directory', '*.txt'));
    }

    public function test_file_is_not_a_directory_to_search(): void
    {
        $this->touch('file.txt');

        $this->assertToolError("Directory 'file.txt' does not exist.", (new GlobPathTool($this->tempDir))('file.txt', '*'));
    }

    public function test_glob_no_matches(): void
    {
        $this->assertSame(
            "No matches found for pattern '*.pdf' in directory '{$this->tempDir}'.",
            ($this->tool)($this->tempDir, '*.pdf')
        );
    }

    public function test_lists_matches_relative_to_the_directory_in_natural_order(): void
    {
        $this->touch('file10.txt', 'file2.txt', 'file1.txt', 'notes.log');

        $this->assertSame(
            "Found 3 match(es) for pattern '*.txt' in directory '{$this->tempDir}':\n\n"
            . "  - file1.txt\n"
            . "  - file2.txt\n"
            . "  - file10.txt\n",
            ($this->tool)($this->tempDir, '*.txt')
        );
    }

    /**
     * @return iterable<string, array{string, string[], string[]}>
     */
    public static function patternProvider(): iterable
    {
        yield 'every file' => ['*', ['file1.txt', 'file2.php', 'file3.md'], []];
        yield 'question mark wildcard' => ['file?.txt', ['file1.txt', 'file2.txt'], ['data.txt']];
        yield 'any extension' => ['test.*', ['test.txt', 'test.log'], ['other.md']];
        yield 'character class' => ['file[12].txt', ['file1.txt', 'file2.txt'], ['file3.txt']];
    }

    /**
     * @param string[] $matching
     * @param string[] $other
     */
    #[DataProvider('patternProvider')]
    public function test_matches_only_the_files_selected_by_the_pattern(string $pattern, array $matching, array $other): void
    {
        $this->touch(...$matching, ...$other);

        $result = ($this->tool)($this->tempDir, $pattern);

        $this->assertStringStartsWith('Found ' . count($matching) . ' match(es)', $result);
        foreach ($matching as $file) {
            $this->assertStringContainsString("  - {$file}\n", $result);
        }
        foreach ($other as $file) {
            $this->assertStringNotContainsString($file, $result);
        }
    }

    public function test_recursive_pattern_walks_every_level(): void
    {
        mkdir($this->tempDir . '/level1/level2', 0o755, true);
        $this->touch('root.txt', 'root.php', 'level1/l1.txt', 'level1/level2/l2.txt', 'level1/level2/l2.php');

        $this->assertSame(
            "Found 3 match(es) for pattern '*.txt' in directory '{$this->tempDir}':\n\n"
            . "  - level1/l1.txt\n"
            . "  - level1/level2/l2.txt\n"
            . "  - root.txt\n",
            ($this->tool)($this->tempDir, '**/*.txt')
        );
    }

    public function test_non_recursive_does_not_include_nested(): void
    {
        mkdir($this->tempDir . '/nested');
        $this->touch('root.txt', 'nested/nested.txt');

        $result = ($this->tool)($this->tempDir, '*.txt');

        $this->assertStringStartsWith('Found 1 match(es)', $result);
        $this->assertStringNotContainsString('nested.txt', $result);
    }

    public function test_directories_match_the_pattern_too(): void
    {
        mkdir($this->tempDir . '/docs');

        $this->assertStringContainsString("  - docs\n", ($this->tool)($this->tempDir, '*'));
    }

    public function test_glob_empty_directory(): void
    {
        $this->assertSame(
            "No matches found for pattern '*' in directory '{$this->tempDir}'.",
            ($this->tool)($this->tempDir, '*')
        );
    }

    public function test_relative_directory_resolves_from_the_scope(): void
    {
        mkdir($this->tempDir . '/src');
        $this->touch('src/a.php', 'b.php');

        $result = (new GlobPathTool($this->tempDir))('src', '*.php');

        $this->assertSame("Found 1 match(es) for pattern '*.php' in directory 'src':\n\n  - a.php\n", $result);
    }

    public function test_directory_outside_the_scope_is_refused(): void
    {
        mkdir($this->tempDir . '/scope');

        $this->assertToolError(
            "Access denied: '..' is outside the working scope '{$this->tempDir}/scope'.",
            (new GlobPathTool($this->tempDir . '/scope'))('..', '*')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function escapingPatternProvider(): iterable
    {
        yield 'parent segment' => ['../*'];
        yield 'parent spelled as a character class' => ['[.][.]/*'];
        yield 'parent below a subdirectory' => ['sub/../../*'];
        yield 'recursive parent' => ['**/../*.txt'];
    }

    #[DataProvider('escapingPatternProvider')]
    public function test_pattern_cannot_list_files_outside_the_scope(string $pattern): void
    {
        mkdir($this->tempDir . '/scope/sub', 0o755, true);
        $this->touch('secret.txt', 'scope/inside.txt');

        $result = (new GlobPathTool($this->tempDir . '/scope'))('.', $pattern);

        $this->assertStringNotContainsString('secret.txt', $result);
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('glob_path', $this->tool->getName());
        $this->assertSame('Find files matching a glob pattern in a directory.', $this->tool->getDescription());
        $this->assertSame(
            ['directory', 'pattern'],
            array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties())
        );
    }

    protected function touch(string ...$files): void
    {
        foreach ($files as $file) {
            file_put_contents($this->tempDir . '/' . $file, 'content');
        }
    }
}
