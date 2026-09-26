<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\Toolkits\FileSystem\DeleteFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\EditFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\FileSystemToolkit;
use NeuronAI\Tools\Toolkits\FileSystem\GlobPathTool;
use NeuronAI\Tools\Toolkits\FileSystem\GrepFileContentTool;
use NeuronAI\Tools\Toolkits\FileSystem\ParseFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\ReadFileTool;
use NeuronAI\Tools\Toolkits\FileSystem\WriteFileTool;
use NeuronAI\Tools\ToolInterface;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function str_replace;

class FileSystemToolkitTest extends TestCase
{
    use FileSystemSandbox;

    protected string $outside;

    protected string $scope;

    protected function setUp(): void
    {
        $this->outside = $this->createSandbox('neuron_toolkit');
        $this->scope = $this->outside . '/scope';

        mkdir($this->scope . '/sub', 0o755, true);
        file_put_contents($this->outside . '/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->outside);
    }

    public function test_provides_every_tool_with_and_without_scope(): void
    {
        $expected = [
            'read_file',
            'grep_file_content',
            'glob_path',
            'parse_file',
            'write_file',
            'delete_file',
            'edit_file',
            'bash',
        ];

        $this->assertSame($expected, $this->toolNames(FileSystemToolkit::make()->tools()));
        $this->assertSame($expected, $this->toolNames(FileSystemToolkit::make($this->scope)->tools()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function secretPathProvider(): iterable
    {
        yield 'absolute path' => ['{outside}/secret.txt'];
        yield 'relative traversal' => ['../secret.txt'];
        yield 'traversal below a subdirectory' => ['sub/../../secret.txt'];
    }

    #[DataProvider('secretPathProvider')]
    public function test_every_tool_refuses_paths_outside_the_scope(string $path): void
    {
        $this->assertEveryToolIsDenied(str_replace('{outside}', $this->outside, $path));
    }

    public function test_every_tool_refuses_a_symlink_escaping_the_scope(): void
    {
        $this->symlinkOrSkip($this->outside . '/secret.txt', $this->scope . '/link.txt');

        $this->assertEveryToolIsDenied('link.txt', $this->outside);
    }

    public function test_guidelines_without_scope(): void
    {
        $this->assertSame(
            'Explore and read files and directories. Use glob_path to discover files, then read_file or grep_file_content as needed. For documents (PDF, HTML), use parse_file.',
            FileSystemToolkit::make()->guidelines()
        );
    }

    public function test_guidelines_mention_the_scope(): void
    {
        $this->assertStringContainsString("confined to '{$this->scope}'", FileSystemToolkit::make($this->scope)->guidelines());
    }

    public function test_missing_scope_fails_when_tools_are_provided(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage("requires an existing directory as scope, '{$this->outside}/missing' given.");

        FileSystemToolkit::make($this->outside . '/missing')->tools();
    }

    protected function assertEveryToolIsDenied(string $secret, ?string $directory = null): void
    {
        $calls = [
            ReadFileTool::class => [$secret],
            GrepFileContentTool::class => [$secret, '/secret/'],
            GlobPathTool::class => [$directory ?? $this->outside, '*.txt'],
            ParseFileTool::class => [$secret],
            WriteFileTool::class => [$secret, 'overwritten'],
            DeleteFileTool::class => [$secret],
            EditFileTool::class => [$secret, 'secret', 'edited'],
            BashTool::class => ['echo pwned > secret.txt', $directory ?? $this->outside],
        ];

        foreach (FileSystemToolkit::make($this->scope)->tools() as $tool) {
            $result = ($tool)(...$calls[$tool::class]);

            $this->assertInstanceOf(ToolOutput::class, $result, $tool::class);
            $this->assertTrue($result->isError(), $tool::class);
            $this->assertStringStartsWith('Access denied:', $result->getText(), $tool::class);
        }

        $this->assertSame('secret', file_get_contents($this->outside . '/secret.txt'));
    }

    /**
     * @param ToolInterface[] $tools
     * @return string[]
     */
    protected function toolNames(array $tools): array
    {
        return array_map(fn (ToolInterface $tool): string => $tool->getName(), $tools);
    }
}
