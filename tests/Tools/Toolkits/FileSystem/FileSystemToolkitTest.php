<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

class FileSystemToolkitTest extends TestCase
{
    private string $outside;

    private string $scope;

    protected function setUp(): void
    {
        $this->outside = sys_get_temp_dir() . '/neuron_toolkit_' . uniqid();
        $this->scope = $this->outside . '/scope';

        mkdir($this->scope, 0o755, true);
        file_put_contents($this->outside . '/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        unlink($this->outside . '/secret.txt');
        rmdir($this->scope);
        rmdir($this->outside);
    }

    public function test_provides_every_tool_with_and_without_scope(): void
    {
        $this->assertCount(8, FileSystemToolkit::make()->tools());
        $this->assertCount(8, FileSystemToolkit::make($this->scope)->tools());
    }

    public function test_every_tool_refuses_paths_outside_the_scope(): void
    {
        $secret = $this->outside . '/secret.txt';
        $calls = [
            ReadFileTool::class => [$secret],
            GrepFileContentTool::class => [$secret, '/secret/'],
            GlobPathTool::class => [$this->outside, '*.txt'],
            ParseFileTool::class => [$secret],
            WriteFileTool::class => [$secret, 'overwritten'],
            DeleteFileTool::class => [$secret],
            EditFileTool::class => [$secret, 'secret', 'edited'],
            BashTool::class => ['echo hello', $this->outside],
        ];

        foreach (FileSystemToolkit::make($this->scope)->tools() as $tool) {
            $result = ($tool)(...$calls[$tool::class]);

            $this->assertInstanceOf(ToolOutput::class, $result, $tool::class);
            $this->assertTrue($result->isError(), $tool::class);
            $this->assertStringStartsWith('Access denied:', $result->getText(), $tool::class);
        }

        $this->assertSame('secret', file_get_contents($secret));
    }

    public function test_guidelines_mention_the_scope(): void
    {
        $this->assertStringNotContainsString('confined', FileSystemToolkit::make()->guidelines());
        $this->assertStringContainsString("confined to '{$this->scope}'", FileSystemToolkit::make($this->scope)->guidelines());
    }

    public function test_missing_scope_fails_when_tools_are_provided(): void
    {
        $this->expectException(ToolException::class);

        FileSystemToolkit::make($this->outside . '/missing')->tools();
    }
}
