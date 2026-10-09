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
use function mb_check_encoding;
use function microtime;
use function mkdir;
use function str_repeat;

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

    public function test_stdout_and_stderr_are_merged_in_the_order_they_were_written(): void
    {
        $result = (new BashTool())('echo out; echo err >&2; echo again');

        $this->assertSame("out\nerr\nagain\n", $result['output']);
    }

    public function test_a_command_writing_more_than_a_pipe_buffer_to_stderr_completes(): void
    {
        // The outer timeout turns a deadlock into a failure: without it the tool would never return
        $result = (new BashTool(outputLimit: 300000))("timeout 5 sh -c 'head -c 200000 /dev/zero | tr \"\\\\0\" x >&2; echo done'");

        $this->assertIsArray($result);
        $this->assertSame(str_repeat('x', 200000) . "done\n", $result['output']);
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

    public function test_command_runs_under_bash(): void
    {
        $result = (new BashTool())('set -o pipefail; [[ -n $BASH_VERSION ]] && echo bash');

        $this->assertSame("bash\n", $result['output']);
    }

    public function test_command_with_a_nul_byte_is_refused_before_running(): void
    {
        $result = (new BashTool())("touch {$this->tempDir}/ran\0-never");

        $this->assertToolError('The command contains a NUL byte, which no shell command can carry. Remove it and try again.', $result);
        $this->assertFileDoesNotExist($this->tempDir . '/ran');
    }

    public function test_command_running_past_the_timeout_is_killed_and_reports_its_output(): void
    {
        $start = microtime(true);

        $result = (new BashTool(timeout: 1))('echo started; sleep 10');

        $this->assertToolError("Command killed: it exceeded the 1-second timeout.\n\nstarted\n", $result);
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_command_ignoring_sigterm_is_killed_at_the_timeout(): void
    {
        $start = microtime(true);

        $result = (new BashTool(timeout: 1))('trap "" TERM; sleep 10');

        $this->assertToolError('Command killed: it exceeded the 1-second timeout.', $result);
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_process_left_in_the_background_does_not_keep_the_tool_waiting(): void
    {
        $start = microtime(true);

        $result = (new BashTool())('sleep 10 & echo started');

        $this->assertSame("started\n", $result['output']);
        $this->assertLessThan(5, microtime(true) - $start);
    }

    public function test_output_past_the_limit_is_truncated_and_the_command_still_completes(): void
    {
        // More than a pipe holds: a command left unread past the limit would never reach the touch
        $result = (new BashTool(outputLimit: 10))("head -c 200000 /dev/zero | tr '\\0' x; touch {$this->tempDir}/completed");

        $this->assertSame("xxxxxxxxxx\n\n[Output truncated: only the first 10 bytes are shown.]", $result['output']);
        $this->assertFileExists($this->tempDir . '/completed');
    }

    public function test_output_of_exactly_the_limit_is_returned_whole(): void
    {
        $result = (new BashTool(outputLimit: 6))('echo hello');

        $this->assertSame("hello\n", $result['output']);
    }

    public function test_truncated_output_of_a_failing_command_keeps_the_exit_code(): void
    {
        $this->assertToolError(
            "Command exited with code 3.\n\n01234\n\n[Output truncated: only the first 5 bytes are shown.]",
            (new BashTool(outputLimit: 5))('echo 0123456789; exit 3')
        );
    }

    public function test_setters_replace_the_limits_given_to_the_constructor(): void
    {
        $tool = (new BashTool(timeout: 60, outputLimit: 1000))->setTimeout(1)->setOutputLimit(5);

        $this->assertToolError(
            "Command killed: it exceeded the 1-second timeout.\n\n01234\n\n[Output truncated: only the first 5 bytes are shown.]",
            $tool('echo 0123456789; sleep 10')
        );
    }

    public function test_invalid_utf8_printed_by_a_failing_command_is_replaced(): void
    {
        $result = (new BashTool())("printf 'caf\\351 au lait'; exit 1");

        $this->assertInstanceOf(ToolOutput::class, $result);
        $this->assertTrue(mb_check_encoding($result->getText(), 'UTF-8'));
        $this->assertStringStartsWith("Command exited with code 1.\n\ncaf", $result->getText());
        $this->assertStringEndsWith(' au lait', $result->getText());
    }

    public function test_command_ended_by_a_signal_reports_the_shell_exit_code(): void
    {
        $this->assertToolError('Command exited with code 137.', (new BashTool())('kill -9 $$'));
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
