<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

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
        $tool = new EditFileTool();
        $result = ($tool)('/non/existent/file.txt', 'search', 'replace');

        $this->assertSame('error', $result['status']);
    }

    public function test_returns_error_when_search_not_found(): void
    {
        file_put_contents($this->tempFile, 'content');

        $tool = new EditFileTool();
        $result = ($tool)($this->tempFile, 'not present', 'replace');

        $this->assertSame('error', $result['status']);
    }

    public function test_tool_name(): void
    {
        $this->assertSame('edit_file', (new EditFileTool())->getName());
    }
}
