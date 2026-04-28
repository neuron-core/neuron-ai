<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\FileSystem;

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

    public function testInvokeSuccessfulCommand(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello');

        $this->assertSame('success', $result['status']);
        $this->assertSame('bash', $result['operation']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertStringContainsString('hello', $result['output']);
    }

    public function testInvokeFailingCommandReturnsError(): void
    {
        $tool = new BashTool();
        $result = ($tool)('exit 1');

        $this->assertSame('error', $result['status']);
        $this->assertSame(1, $result['exit_code']);
    }

    public function testInvokeNonZeroExitCodeIsReported(): void
    {
        $tool = new BashTool();
        $result = ($tool)('exit 42');

        $this->assertSame('error', $result['status']);
        $this->assertSame(42, $result['exit_code']);
        $this->assertStringContainsString('42', $result['message']);
    }

    public function testInvokeCapturesStdout(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo "stdout output"');

        $this->assertStringContainsString('stdout output', $result['output']);
    }

    public function testInvokeCapturesStderr(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo "stderr output" >&2');

        $this->assertStringContainsString('stderr output', $result['output']);
    }

    public function testInvokeIncludesCommandInResult(): void
    {
        $command = 'echo test';
        $tool = new BashTool();
        $result = ($tool)($command);

        $this->assertSame($command, $result['command']);
    }

    public function testInvokeDefaultsToCurrentWorkingDirectory(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello');

        $this->assertArrayHasKey('working_directory', $result);
        $this->assertSame(getcwd(), $result['working_directory']);
    }

    public function testInvokeWithExplicitWorkingDirectory(): void
    {
        $tool = new BashTool();
        $result = ($tool)('pwd', $this->tempDir);

        $this->assertSame('success', $result['status']);
        $this->assertSame($this->tempDir, $result['working_directory']);
        $this->assertStringContainsString($this->tempDir, $result['output']);
    }

    public function testInvokeReturnsErrorForNonExistentWorkingDirectory(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello', '/non/existent/directory');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('does not exist', $result['message']);
    }

    public function testInvokeIncludesMessageOnSuccess(): void
    {
        $tool = new BashTool();
        $result = ($tool)('echo hello');

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('successfully', $result['message']);
    }

    public function testGetName(): void
    {
        $tool = new BashTool();
        $this->assertSame('bash', $tool->getName());
    }

    public function testGetDescription(): void
    {
        $tool = new BashTool();
        $this->assertIsString($tool->getDescription());
        $this->assertNotEmpty($tool->getDescription());
    }

    public function testGetProperties(): void
    {
        $tool = new BashTool();
        $properties = $tool->getProperties();

        $this->assertCount(2, $properties);
    }

    public function testCommandPropertyIsRequired(): void
    {
        $tool = new BashTool();
        $required = $tool->getRequiredProperties();

        $this->assertContains('command', $required);
        $this->assertNotContains('working_directory', $required);
    }
}
