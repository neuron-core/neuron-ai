<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tests\Support\FileSystemSandbox;
use NeuronAI\Tests\Support\ToolErrorAssertions;
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolPropertyInterface;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function getcwd;
use function mkdir;

class BashToolTest extends TestCase
{
    use FileSystemSandbox;
    use ToolErrorAssertions;

    protected string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = $this->createSandbox('neuron_bash');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->tempDir);
    }

    public function test_successful_command_returns_the_full_result(): void
    {
        $this->assertSame([
            'status' => 'success',
            'operation' => 'bash',
            'command' => 'echo hello',
            'output' => "hello\n",
            'exit_code' => 0,
            'working_directory' => $this->tempDir,
            'message' => 'Command executed successfully.',
        ], (new BashTool())('echo hello', $this->tempDir));
    }

    public function test_failing_command_without_output_reports_only_the_exit_code(): void
    {
        $this->assertToolError('Command exited with code 1.', (new BashTool())('exit 1'));
    }

    public function test_non_zero_exit_code_is_reported_with_the_output(): void
    {
        $this->assertToolError("Command exited with code 42.\n\nboom\n", (new BashTool())('echo boom && exit 42'));
    }

    public function test_stderr_is_appended_after_stdout(): void
    {
        $result = (new BashTool())('echo out; echo err >&2');

        $this->assertSame("out\n\nerr\n", $result['output']);
    }

    public function test_stderr_alone_is_the_output(): void
    {
        $result = (new BashTool())('echo "stderr output" >&2');

        $this->assertSame("stderr output\n", $result['output']);
    }

    public function test_stderr_of_a_failing_command_is_reported(): void
    {
        $this->assertToolError("Command exited with code 3.\n\nbad input\n", (new BashTool())('echo "bad input" >&2; exit 3'));
    }

    public function test_command_reads_a_closed_stdin_instead_of_waiting_for_input(): void
    {
        $result = (new BashTool())('timeout 2 cat; echo "cat exited with $?"');

        $this->assertSame("cat exited with 0\n", $result['output']);
    }

    public function test_defaults_to_current_working_directory_without_scope(): void
    {
        $result = (new BashTool())('pwd');

        $this->assertSame(getcwd(), $result['working_directory']);
        $this->assertSame(getcwd() . "\n", $result['output']);
    }

    public function test_runs_in_the_explicit_working_directory(): void
    {
        $result = (new BashTool())('pwd', $this->tempDir);

        $this->assertSame($this->tempDir, $result['working_directory']);
        $this->assertSame($this->tempDir . "\n", $result['output']);
    }

    public function test_returns_error_for_non_existent_working_directory(): void
    {
        $this->assertToolError(
            "Working directory '/non/existent/directory' does not exist.",
            (new BashTool())('echo hello', '/non/existent/directory')
        );
    }

    public function test_working_directory_must_be_a_directory(): void
    {
        file_put_contents($this->tempDir . '/file.txt', 'x');

        $this->assertToolError(
            "Working directory 'file.txt' does not exist.",
            (new BashTool($this->tempDir))('touch ran', 'file.txt')
        );
        $this->assertFileDoesNotExist($this->tempDir . '/ran');
    }

    public function test_working_directory_is_required_only_under_a_scope(): void
    {
        $this->assertSame(['command'], (new BashTool())->getRequiredProperties());
        $this->assertSame(['command', 'working_directory'], (new BashTool($this->tempDir))->getRequiredProperties());
    }

    public function test_working_directory_defaults_to_the_scope(): void
    {
        $result = (new BashTool($this->tempDir))('pwd');

        $this->assertSame($this->tempDir, $result['working_directory']);
        $this->assertSame($this->tempDir . "\n", $result['output']);
    }

    public function test_relative_working_directory_resolves_from_the_scope(): void
    {
        mkdir($this->tempDir . '/project');

        $result = (new BashTool($this->tempDir))('pwd', 'project');

        $this->assertSame($this->tempDir . '/project', $result['working_directory']);
    }

    public function test_working_directory_outside_the_scope_is_refused_before_running_the_command(): void
    {
        mkdir($this->tempDir . '/scope');
        $sentinel = $this->tempDir . '/ran';

        $result = (new BashTool($this->tempDir . '/scope'))("touch {$sentinel}", '..');

        $this->assertToolError("Access denied: '..' is outside the working scope '{$this->tempDir}/scope'.", $result);
        $this->assertFileDoesNotExist($sentinel);
    }

    public function test_symlinked_working_directory_pointing_outside_the_scope_is_refused(): void
    {
        mkdir($this->tempDir . '/scope');
        mkdir($this->tempDir . '/elsewhere');
        $this->symlinkOrSkip($this->tempDir . '/elsewhere', $this->tempDir . '/scope/link');

        $result = (new BashTool($this->tempDir . '/scope'))('touch ran', 'link');

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertStringStartsWith("Access denied: 'link'", $result->getText());
        $this->assertFileDoesNotExist($this->tempDir . '/elsewhere/ran');
    }

    public function test_tool_schema(): void
    {
        $tool = new BashTool();

        $this->assertSame('bash', $tool->getName());
        $this->assertSame(
            ['command', 'working_directory'],
            array_map(fn (ToolPropertyInterface $property): string => $property->getName(), $tool->getProperties())
        );
    }
}
