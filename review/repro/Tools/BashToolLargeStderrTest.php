<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\Toolkits\FileSystem\BashTool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function substr;

class BashToolLargeStderrTest extends TestCase
{
    public function test_command_writing_more_than_a_pipe_buffer_to_stderr_completes(): void
    {
        // The outer timeout turns the deadlock into a failure: without it the tool never returns.
        $result = (new BashTool())("timeout 3 sh -c 'head -c 200000 /dev/zero | tr \"\\\\0\" x >&2; echo done'");

        $this->assertNotInstanceOf(ToolOutput::class, $result, $result instanceof ToolOutput ? substr($result->getText(), 0, 40) : '');
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame("done\n\n" . str_repeat('x', 200000), $result['output']);
    }
}
