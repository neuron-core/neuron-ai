<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function mkdir;

class DeleteFileToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createSandbox('neuron_delete');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_deletes_file(): void
    {
        $file = $this->tempDir . '/file.txt';
        file_put_contents($file, 'content');

        $this->assertSame([
            'status' => 'success',
            'operation' => 'delete_file',
            'file_path' => $file,
            'message' => "File '{$file}' deleted successfully.",
        ], (new DeleteFileTool())($file));
        $this->assertFileDoesNotExist($file);
    }

    public function test_deletes_only_the_given_file(): void
    {
        file_put_contents($this->tempDir . '/a.txt', 'a');
        file_put_contents($this->tempDir . '/b.txt', 'b');

        (new DeleteFileTool($this->tempDir))('a.txt');

        $this->assertFileDoesNotExist($this->tempDir . '/a.txt');
        $this->assertFileExists($this->tempDir . '/b.txt');
    }

    public function test_returns_error_when_file_does_not_exist(): void
    {
        $this->assertToolError("File '/non/existent/file.txt' does not exist.", (new DeleteFileTool())('/non/existent/file.txt'));
    }

    public function test_second_delete_of_the_same_file_reports_it_missing(): void
    {
        file_put_contents($this->tempDir . '/once.txt', 'x');
        $tool = new DeleteFileTool($this->tempDir);

        $tool('once.txt');

        $this->assertToolError("File 'once.txt' does not exist.", $tool('once.txt'));
    }

    public function test_directory_is_never_deleted(): void
    {
        mkdir($this->tempDir . '/dir');
        file_put_contents($this->tempDir . '/dir/keep.txt', 'keep');

        $this->assertToolError(
            "'dir' is not a file. Directories cannot be deleted with this tool.",
            (new DeleteFileTool($this->tempDir))('dir')
        );
        $this->assertFileExists($this->tempDir . '/dir/keep.txt');
    }

    public function test_scope_root_itself_cannot_be_deleted(): void
    {
        $this->assertToolError(
            "'.' is not a file. Directories cannot be deleted with this tool.",
            (new DeleteFileTool($this->tempDir))('.')
        );
        $this->assertDirectoryExists($this->tempDir);
    }

    public function test_file_outside_the_scope_survives(): void
    {
        mkdir($this->tempDir . '/scope');
        file_put_contents($this->tempDir . '/precious.txt', 'keep');

        $result = (new DeleteFileTool($this->tempDir . '/scope'))('../precious.txt');

        $this->assertToolError("Access denied: '../precious.txt' is outside the working scope '{$this->tempDir}/scope'.", $result);
        $this->assertFileExists($this->tempDir . '/precious.txt');
    }

    public function test_symlink_pointing_outside_the_scope_does_not_delete_its_target(): void
    {
        mkdir($this->tempDir . '/scope');
        file_put_contents($this->tempDir . '/precious.txt', 'keep');
        $this->symlinkOrSkip($this->tempDir . '/precious.txt', $this->tempDir . '/scope/link.txt');

        $result = (new DeleteFileTool($this->tempDir . '/scope'))('link.txt');

        $this->assertToolError("Access denied: 'link.txt' is outside the working scope '{$this->tempDir}/scope'.", $result);
        $this->assertFileExists($this->tempDir . '/precious.txt');
    }

    public function test_tool_schema(): void
    {
        $tool = new DeleteFileTool();

        $this->assertSame('delete_file', $tool->getName());
        $this->assertSame(['file_path'], $tool->getRequiredProperties());
    }
}
