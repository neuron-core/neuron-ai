<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

use function fclose;
use function getcwd;
use function is_dir;
use function proc_close;
use function proc_open;
use function stream_get_contents;

/**
 * Execute a bash command and return its output.
 */
class BashTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'bash',
            description: 'Execute a bash command and return its output. Use for running scripts, build tools, tests, linters, or any shell operation.',
        );
    }

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
                description: 'The working directory to run the command in. Defaults to the current working directory.',
                required: false,
            ),
        ];
    }

    public function __invoke(string $command, ?string $working_directory = null): array
    {
        $cwd = $working_directory ?? getcwd();

        if ($working_directory !== null && !is_dir($working_directory)) {
            return [
                'status' => 'error',
                'operation' => 'bash',
                'command' => $command,
                'output' => '',
                'exit_code' => 1,
                'working_directory' => $working_directory,
                'message' => "Working directory '{$working_directory}' does not exist.",
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $cwd);

        if ($process === false) {
            return [
                'status' => 'error',
                'operation' => 'bash',
                'command' => $command,
                'output' => '',
                'exit_code' => 1,
                'working_directory' => $cwd,
                'message' => 'Failed to start process.',
            ];
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

        $status = $exitCode === 0 ? 'success' : 'error';
        $message = $exitCode === 0
            ? 'Command executed successfully.'
            : "Command exited with code {$exitCode}.";

        return [
            'status' => $status,
            'operation' => 'bash',
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'working_directory' => $cwd,
            'message' => $message,
        ];
    }
}
