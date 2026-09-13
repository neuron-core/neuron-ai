<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class EditFileToolTest extends TestCase
{
    use ToolErrorAssertions;

    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/synapse_edit_test_' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function test_replaces_text_in_file(): void
    {
        file_put_contents($this->tempFile, 'foo bar baz');

        $tool = new EditFileTool();
        $result = ($tool)($this->tempFile, 'bar', 'qux');

        $this->assertSame('success', $result['status']);
        $this->assertSame('edit_file', $result['operation']);
        $this->assertSame('foo qux baz', file_get_contents($this->tempFile));
    }

    public function test_returns_error_when_file_does_not_exist(): void
    {
        $this->assertToolError(
            "File '/non/existent/file.txt' does not exist.",
            (new EditFileTool())('/non/existent/file.txt', 'search', 'replace')
        );
    }

    public function test_returns_error_when_search_not_found(): void
    {
        file_put_contents($this->tempFile, 'content');

        $this->assertToolError(
            "Search string not found in '{$this->tempFile}'. Ensure the text matches exactly.",
            (new EditFileTool())($this->tempFile, 'not present', 'replace')
        );
    }

    public function test_tool_name(): void
    {
        $this->assertSame('edit_file', (new EditFileTool())->getName());
    }
}
