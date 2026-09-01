<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use PHPUnit\Framework\TestCase;

use function getcwd;
use function is_dir;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;

class BashToolTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/synapse_bash_test_' . uniqid();
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_invoke_successful_command(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello');

        $this->assertSame('success', $result['status']);
        $this->assertSame('bash', $result['operation']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('hello', $result['output']);
    }

    public function test_invoke_failing_command_returns_error(): void
    {
        $tool = new BashTool();
        $result = ($tool)('exit 1');

        $this->assertSame('error', $result['status']);
        $this->assertSame(1, $result['exit_code']);
    }

    public function test_invoke_non_zero_exit_code_is_reported(): void
    {
        $tool = new BashTool();
        $result = ($tool)('exit 42');

        $this->assertSame('error', $result['status']);
        $this->assertSame(42, $result['exit_code']);
        $this->assertStringContainsString('42', $result['message']);
    }

    public function test_invoke_captures_stdout(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo "stdout output"');

        $this->assertStringContainsString('stdout output', $result['output']);
    }

    public function test_invoke_captures_stderr(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo "stderr output" >&2');

        $this->assertStringContainsString('stderr output', $result['output']);
    }

    public function test_invoke_includes_command_in_result(): void
    {
        $command = 'echo test';
        $tool = new BashTool();
        $result = ($tool)($command);

        $this->assertSame($command, $result['command']);
    }

    public function test_invoke_defaults_to_current_working_directory(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello');

        $this->assertArrayHasKey('working_directory', $result);
        $this->assertSame(getcwd(), $result['working_directory']);
    }

    public function test_invoke_with_explicit_working_directory(): void
    {
        $tool = new BashTool();
        $result = ($tool)('pwd', $this->tempDir);

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->tempDir, $result['working_directory']);
        $this->assertStringContainsString($this->tempDir, $result['output']);
    }

    public function test_invoke_returns_error_for_non_existent_working_directory(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello', '/non/existent/directory');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('does not exist', $result['message']);
    }

    public function test_invoke_includes_message_on_success(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello');

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('successfully', $result['message']);
    }

    public function test_get_name(): void
    {
        $tool = new BashTool();
        $this->assertSame('bash', $tool->getName());
    }

    public function test_get_description(): void
    {
        $tool = new BashTool();
        $this->assertIsString($tool->getDescription());
        $this->assertNotEmpty($tool->getDescription());
    }

    public function test_get_properties(): void
    {
        $tool = new BashTool();
        $properties = $tool->getProperties();

        $this->assertCount(2, $properties);
    }

    public function test_command_property_is_required(): void
    {
        $tool = new BashTool();
        $required = $tool->getRequiredProperties();

        $this->assertContains('command', $required);
        $this->assertNotContains('working_directory', $required);
    }
}
