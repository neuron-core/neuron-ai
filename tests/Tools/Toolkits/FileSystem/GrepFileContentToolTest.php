<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function mkdir;
use function str_repeat;

class GrepFileContentToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected GrepFileContentTool $tool;

    protected string $tempDir;

    protected string $tempFile;

    protected function setUp(): void
    {
        $this->tool = new GrepFileContentTool();
        $this->tempDir = $this->createSandbox('neuron_grep');
        $this->tempFile = $this->tempDir . '/file.txt';
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_grep_non_existent_file(): void
    {
        $this->assertToolError("File '/non/existent/file.txt' does not exist.", ($this->tool)('/non/existent/file.txt', 'pattern'));
    }

    public function test_directory_is_not_searched(): void
    {
        mkdir($this->tempDir . '/dir');

        $this->assertToolError("File 'dir' does not exist.", (new GrepFileContentTool($this->tempDir))('dir', '/x/'));
    }

    public function test_grep_no_matches(): void
    {
        file_put_contents($this->tempFile, "Hello\nWorld\nTest");

        $this->assertSame(
            "No matches found for pattern '/notfound/' in file '{$this->tempFile}'.",
            ($this->tool)($this->tempFile, '/notfound/')
        );
    }

    public function test_reports_every_match_with_its_line_number(): void
    {
        file_put_contents($this->tempFile, "Count: 42\nnothing\nCount: 100 and 7\nCount: 999");

        $this->assertSame(
            "Found 4 match(es) for pattern '/\\d+/' in file '{$this->tempFile}':\n\n"
            . "  Match 1 (line 1): 42\n"
            . "  Match 2 (line 3): 100\n"
            . "  Match 3 (line 3): 7\n"
            . "  Match 4 (line 4): 999\n",
            ($this->tool)($this->tempFile, '/\d+/')
        );
    }

    public function test_match_at_the_start_of_a_line_is_attributed_to_that_line(): void
    {
        file_put_contents($this->tempFile, "abc\ndef\n");

        $this->assertStringContainsString('Match 1 (line 2): def', ($this->tool)($this->tempFile, '/def/'));
    }

    public function test_match_spanning_lines_is_reported_on_its_first_line(): void
    {
        file_put_contents($this->tempFile, "one\nstart\nend\n");

        $this->assertStringContainsString("Match 1 (line 2): start\nend", ($this->tool)($this->tempFile, '/start\nend/'));
    }

    public function test_grep_case_sensitive_by_default(): void
    {
        file_put_contents($this->tempFile, "Hello\nhello\nHELLO");

        $result = ($this->tool)($this->tempFile, '/Hello/');

        $this->assertStringStartsWith('Found 1 match(es)', $result);
        $this->assertStringContainsString('Match 1 (line 1): Hello', $result);
    }

    public function test_grep_honours_pattern_modifiers(): void
    {
        file_put_contents($this->tempFile, "Hello\nhello\nHELLO");

        $result = ($this->tool)($this->tempFile, '/hello/i');

        $this->assertStringContainsString('Match 3 (line 3): HELLO', $result);
    }

    public function test_match_of_exactly_one_hundred_characters_is_not_truncated(): void
    {
        file_put_contents($this->tempFile, str_repeat('A', 100));

        $this->assertStringContainsString(
            'Match 1 (line 1): ' . str_repeat('A', 100) . "\n",
            ($this->tool)($this->tempFile, '/A+/')
        );
    }

    public function test_longer_match_is_truncated_to_one_hundred_characters(): void
    {
        file_put_contents($this->tempFile, str_repeat('A', 101));

        $this->assertStringContainsString(
            'Match 1 (line 1): ' . str_repeat('A', 97) . "...\n",
            ($this->tool)($this->tempFile, '/A+/')
        );
    }

    public function test_truncation_does_not_split_multibyte_characters(): void
    {
        file_put_contents($this->tempFile, str_repeat('é', 150));

        $this->assertStringContainsString(
            'Match 1 (line 1): ' . str_repeat('é', 97) . "...\n",
            ($this->tool)($this->tempFile, '/(é)+/u')
        );
    }

    public function test_grep_invalid_regex(): void
    {
        file_put_contents($this->tempFile, 'Test content');

        $this->assertToolError(
            "Invalid regex pattern '/[invalid('. Internal error",
            ($this->tool)($this->tempFile, '/[invalid(')
        );
    }

    public function test_pattern_without_delimiters_is_reported_as_invalid(): void
    {
        file_put_contents($this->tempFile, 'Test content');

        $result = ($this->tool)($this->tempFile, 'Test');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith("Invalid regex pattern 'Test'.", $result->getText());
    }

    public function test_catastrophic_backtracking_is_reported_instead_of_hanging(): void
    {
        file_put_contents($this->tempFile, str_repeat('a', 5000) . 'b');

        $result = ($this->tool)($this->tempFile, '/(a+)+$/');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue($result->isError());
        $this->assertStringStartsWith("Invalid regex pattern '/(a+)+\$/'.", $result->getText());
    }

    public function test_file_outside_the_scope_is_not_searched(): void
    {
        mkdir($this->tempDir . '/scope');
        file_put_contents($this->tempFile, 'password=hunter2');

        $this->assertToolError(
            "Access denied: '../file.txt' is outside the working scope '{$this->tempDir}/scope'.",
            (new GrepFileContentTool($this->tempDir . '/scope'))('../file.txt', '/password=.*/')
        );
    }

    public function test_tool_properties(): void
    {
        $this->assertSame('grep_file_content', $this->tool->getName());
        $this->assertSame('Search for a regex pattern in a file.', $this->tool->getDescription());
        $this->assertSame(
            ['file_path', 'pattern'],
            array_map(fn (ToolPropertyInterface $prop): string => $prop->getName(), $this->tool->getProperties())
        );
    }
}
