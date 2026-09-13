<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use PHPUnit\Framework\TestCase;

use function file_exists;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class DeleteFileToolTest extends TestCase
{
    use ToolErrorAssertions;

    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/synapse_delete_test_' . uniqid() . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    public function test_deletes_file(): void
    {
        file_put_contents($this->tempFile, 'content');

        $tool = new DeleteFileTool();
        $result = ($tool)($this->tempFile);

        $this->assertSame('success', $result['status']);
        $this->assertSame('delete_file', $result['operation']);
        $this->assertFalse(file_exists($this->tempFile));
    }

    public function test_returns_error_when_file_does_not_exist(): void
    {
        $this->assertToolError("File '/non/existent/file.txt' does not exist.", (new DeleteFileTool())('/non/existent/file.txt'));
    }

    public function test_returns_error_when_path_is_directory(): void
    {
        $this->assertToolError(
            "'" . sys_get_temp_dir() . "' is not a file. Directories cannot be deleted with this tool.",
            (new DeleteFileTool())(sys_get_temp_dir())
        );
    }

    public function test_tool_name(): void
    {
        $this->assertSame('delete_file', (new DeleteFileTool())->getName());
    }
}
