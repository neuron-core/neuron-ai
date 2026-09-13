<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function fclose;
use function getcwd;
use function is_dir;
use function proc_close;
use function proc_open;
use function stream_get_contents;

/**
 * Execute a bash command and return its output. A scope makes the working
 * directory mandatory and refuses one outside it, but it cannot confine the
 * command itself: a shell reaches whatever the process can. Sandbox the
 * process when that matters.
 */
class BashTool extends FileSystemTool
{
    protected string $name = 'bash';
    protected ?string $description = 'Execute a bash command and return its output. Use for running scripts, build tools, tests, linters, or any shell operation.';

    protected function properties(): array
    {
        return [
            ToolProperty::make(
                name: 'command',
                type: PropertyType::STRING,
                description: 'The bash command to execute.',
                required: true,
            ),
            ToolProperty::make(
                name: 'working_directory',
                type: PropertyType::STRING,
                description: 'The working directory to run the command in; it must be inside the working scope when one is set. Without a scope it defaults to the current working directory.',
                required: $this->scope !== null,
            ),
        ];
    }

    public function __invoke(string $command, ?string $working_directory = null): array|ToolOutput
    {
        $cwd = $working_directory === null ? ($this->scope ?? getcwd()) : $this->resolve($working_directory);
        if ($cwd instanceof ToolOutput) {
            return $cwd;
        }

        if ($working_directory !== null && !is_dir($cwd)) {
            return ToolOutput::error("Working directory '{$working_directory}' does not exist.");
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if ($process === false) {
            return ToolOutput::error('Failed to start process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = $stdout !== false ? $stdout : '';
        if ($stderr !== false && $stderr !== '') {
            $output .= ($output !== '' ? "\n" : '') . $stderr;
        }

        if ($exitCode !== 0) {
            return ToolOutput::error("Command exited with code {$exitCode}." . ($output !== '' ? "\n\n{$output}" : ''));
        }

        return [
            'status' => 'success',
            'operation' => 'bash',
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'working_directory' => $cwd,
            'message' => 'Command executed successfully.',
        ];
    }
}
