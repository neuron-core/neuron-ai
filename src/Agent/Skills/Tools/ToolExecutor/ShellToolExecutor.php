<?php

declare(strict_types=1);

namespace NeuronAI\Agent\Skills\Tools\ToolExecutor;

use NeuronAI\Agent\Skills\Tools\ToolDefinition;
use NeuronAI\Agent\Skills\Tools\ToolExecutorInterface;
use NeuronAI\Agent\Skills\Tools\ToolResult;

use function escapeshellarg;
use function fclose;
use function is_resource;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_get_contents;
use function str_replace;

class ShellToolExecutor implements ToolExecutorInterface
{
    public function supports(string $type): bool
    {
        return $type === 'shell';
    }

    public function execute(ToolDefinition $definition, array $inputs): ToolResult
    {
        $command = $this->resolveCommand($definition, $inputs);
        $timeout = (int) ($definition->execution['timeout'] ?? 30);
        $retries = (int) ($definition->execution['retry'] ?? 0);
        $workingDirectory = $definition->execution['working_directory'] ?? null;

        $attempt = 0;
        while (true) {
            $result = $this->runProcess($command, $timeout, $workingDirectory);

            if ($result->isSuccess() || $attempt >= $retries) {
                return $result;
            }

            $attempt++;
        }
    }

    private function resolveCommand(ToolDefinition $definition, array $inputs): string
    {
        $template = $definition->execution['command'] ?? '';

        foreach ($inputs as $key => $value) {
            $template = str_replace('{{' . $key . '}}', escapeshellarg((string) $value), $template);
        }

        return $template;
    }

    private function runProcess(string $command, int $timeout, string $workingDirectory = null): ToolResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $workingDirectory);

        if (!is_resource($process)) {
            return new ToolResult(
                exitCode: 1,
                error: 'Failed to start process.',
            );
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = ($stdout !== false ? $stdout : '');
        if ($stderr !== false && $stderr !== '') {
            $output .= ($output !== '' ? "\n" : '') . $stderr;
        }

        return new ToolResult(
            exitCode: $exitCode,
            output: $output,
            error: $exitCode !== 0 ? $stderr ?: null : null,
        );
    }
}
