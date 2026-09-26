<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function mkdir;

class EditFileToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected string $tempDir;

    protected string $tempFile;

    protected function setUp(): void
    {
        $this->tempDir = $this->createSandbox('neuron_edit');
        $this->tempFile = $this->tempDir . '/file.txt';
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_replaces_text_in_file(): void
    {
        file_put_contents($this->tempFile, 'foo bar baz');

        $this->assertSame([
            'status' => 'success',
            'operation' => 'edit_file',
            'file_path' => $this->tempFile,
            'message' => "File '{$this->tempFile}' edited successfully.",
        ], (new EditFileTool())($this->tempFile, 'bar', 'qux'));
        $this->assertSame('foo qux baz', file_get_contents($this->tempFile));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function literalEditProvider(): iterable
    {
        yield 'multiline block with indentation' => [
            "function a() {\n    return 1;\n}\n",
            "    return 1;\n}",
            "    return 2;\n}",
            "function a() {\n    return 2;\n}\n",
        ];
        yield 'replacement with empty string deletes the match' => ['keep drop keep', ' drop', '', 'keep keep'];
        yield 'regex metacharacters in search are literal' => ['price: $1.*', '$1.*', '10', 'price: 10'];
        yield 'backreferences in replace are literal' => ['name', 'name', '$0 \\1 ${1}', '$0 \\1 ${1}'];
        yield 'multibyte text' => ['città 🌍', 'città', 'city', 'city 🌍'];
    }

    #[DataProvider('literalEditProvider')]
    public function test_search_and_replace_are_literal_strings(string $content, string $search, string $replace, string $expected): void
    {
        file_put_contents($this->tempFile, $content);

        (new EditFileTool())($this->tempFile, $search, $replace);

        $this->assertSame($expected, file_get_contents($this->tempFile));
    }

    public function test_every_occurrence_of_the_search_string_is_replaced(): void
    {
        file_put_contents($this->tempFile, "use Old;\nnew Old();\n");

        (new EditFileTool())($this->tempFile, 'Old', 'New');

        $this->assertSame("use New;\nnew New();\n", file_get_contents($this->tempFile));
    }

    public function test_search_must_match_whitespace_exactly(): void
    {
        file_put_contents($this->tempFile, "\treturn 1;");

        $this->assertToolError(
            "Search string not found in '{$this->tempFile}'. Ensure the text matches exactly.",
            (new EditFileTool())($this->tempFile, '    return 1;', 'return 2;')
        );
        $this->assertSame("\treturn 1;", file_get_contents($this->tempFile));
    }

    public function test_search_is_case_sensitive(): void
    {
        file_put_contents($this->tempFile, 'Hello');

        $result = (new EditFileTool())($this->tempFile, 'hello', 'bye');

        $this->assertToolError("Search string not found in '{$this->tempFile}'. Ensure the text matches exactly.", $result);
        $this->assertSame('Hello', file_get_contents($this->tempFile));
    }

    public function test_returns_error_when_file_does_not_exist(): void
    {
        $this->assertToolError(
            "File '/non/existent/file.txt' does not exist.",
            (new EditFileTool())('/non/existent/file.txt', 'search', 'replace')
        );
    }

    public function test_directory_cannot_be_edited(): void
    {
        mkdir($this->tempDir . '/dir');

        $this->assertToolError("File 'dir' does not exist.", (new EditFileTool($this->tempDir))('dir', 'a', 'b'));
    }

    public function test_relative_path_resolves_from_the_scope(): void
    {
        file_put_contents($this->tempFile, 'old');

        $result = (new EditFileTool($this->tempDir))('file.txt', 'old', 'new');

        $this->assertSame('file.txt', $result['file_path']);
        $this->assertSame('new', file_get_contents($this->tempFile));
    }

    public function test_file_outside_the_scope_is_left_untouched(): void
    {
        mkdir($this->tempDir . '/scope');
        file_put_contents($this->tempFile, 'secret');

        $result = (new EditFileTool($this->tempDir . '/scope'))('../file.txt', 'secret', 'leaked');

        $this->assertToolError("Access denied: '../file.txt' is outside the working scope '{$this->tempDir}/scope'.", $result);
        $this->assertSame('secret', file_get_contents($this->tempFile));
    }

    public function test_tool_schema(): void
    {
        $tool = new EditFileTool();

        $this->assertSame('edit_file', $tool->getName());
        $this->assertSame(['file_path', 'search', 'replace'], $tool->getRequiredProperties());
    }
}
