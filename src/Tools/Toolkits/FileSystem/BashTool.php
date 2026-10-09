<?php

declare(strict_types=1);

namespace NeuronAI\Tools\Toolkits\FileSystem;

use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;

use function fclose;
use function getcwd;
use function is_dir;
use function max;
use function mb_scrub;
use function microtime;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function str_contains;
use function stream_get_contents;
use function stream_set_blocking;
use function strlen;
use function substr;
use function usleep;

/**
 * Execute a bash command and return its output. A scope makes the working
 * directory mandatory and refuses one outside it, but it cannot confine the
 * command itself: a shell reaches whatever the process can. Sandbox the
 * process when that matters.
 *
 * A command is killed once it runs past the timeout, and the model gets no
 * more of its output than the limit. Killing stops the shell, not the
 * processes it started.
 */
class BashTool extends FileSystemTool
{
    protected const SIGKILL = 9;

    protected const READ_BYTES = 65536;

    protected string $name = 'bash';
    protected ?string $description = 'Execute a bash command and return its output. Use for running scripts, build tools, tests, linters, or any shell operation.';

    /**
     * @param string|null $scope The directory the working directory must be inside; null leaves it unrestricted.
     * @param int $timeout The seconds a command may run before it is killed.
     * @param int $outputLimit The bytes of output the model gets; the rest is dropped.
     *
     * @throws ToolException
     */
    public function __construct(
        ?string $scope = null,
        protected int $timeout = 120,
        protected int $outputLimit = 30_000,
    ) {
        parent::__construct($scope);
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setOutputLimit(int $outputLimit): self
    {
        $this->outputLimit = $outputLimit;
        return $this;
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
                description: 'The working directory to run the command in; it must be inside the working scope when one is set. Without a scope it defaults to the current working directory.',
                required: $this->scope !== null,
            ),
        ];
    }

    public function __invoke(string $command, ?string $working_directory = null): array|ToolOutput
    {
        // proc_open() throws on a NUL byte, which is the model's mistake to correct and not a bug
        if (str_contains($command, "\0")) {
            return ToolOutput::error('The command contains a NUL byte, which no shell command can carry. Remove it and try again.');
        }

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
            // stderr shares the stdout pipe, so the output keeps the order it was written in
            2 => ['redirect', 1],
        ];

        /** @phpstan-ignore argument.type (PHPStan's stub omits the ['redirect', fd] descriptor, valid since PHP 7.4) */
        $process = proc_open(['bash', '-c', $command], $descriptors, $pipes, $cwd);

        if ($process === false) {
            return ToolOutput::error('Failed to start process.');
        }

        fclose($pipes[0]);

        [$output, $exitCode] = $this->wait($process, $pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        $output = $this->clip($output);

        if ($exitCode !== 0) {
            $failure = $exitCode === null
                ? "Command killed: it exceeded the {$this->timeout}-second timeout."
                : "Command exited with code {$exitCode}.";

            return ToolOutput::error($failure . ($output !== '' ? "\n\n{$output}" : ''));
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

    /**
     * Waits for the shell rather than for the end of its output, which a
     * process left in the background can hold open long after the command
     * returned.
     *
     * @param resource $process
     * @param resource $pipe
     * @return array{string, int|null} The output, one byte past the limit when there was more, and the exit code: null for a command killed at the timeout.
     */
    protected function wait(mixed $process, mixed $pipe): array
    {
        stream_set_blocking($pipe, false);

        $deadline = microtime(true) + $this->timeout;
        $output = '';

        while (true) {
            $status = proc_get_status($process);

            // Reading goes on past the limit: a verbose command would otherwise stall on the full pipe until the timeout
            $room = max(0, $this->outputLimit + 1 - strlen($output));
            $output .= substr((string) stream_get_contents($pipe, max($room, self::READ_BYTES)), 0, $room);

            if (!$status['running']) {
                // bash runs a lone command in its own place, so a signal that ends the command ends the shell: report it as a shell does
                return [$output, $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode']];
            }

            if (microtime(true) >= $deadline) {
                // A command can trap SIGTERM, and proc_close() would then wait for it
                proc_terminate($process, self::SIGKILL);

                return [$output, null];
            }

            usleep(10000); // avoid busy waiting
        }
    }

    /**
     * What the model gets of the output: no more than the limit, and no
     * invalid UTF-8, which would make the next provider request impossible
     * to encode.
     */
    protected function clip(string $output): string
    {
        $clipped = mb_scrub(substr($output, 0, $this->outputLimit), 'UTF-8');

        return strlen($output) > $this->outputLimit
            ? "{$clipped}\n\n[Output truncated: only the first {$this->outputLimit} bytes are shown.]"
            : $clipped;
    }
}
