<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use function array_merge;
use function error_get_last;
use function fclose;
use function fflush;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
use function is_array;
use function is_resource;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_get_contents;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_set_write_buffer;
use function strlen;
use function microtime;
use function strpos;
use function substr;
use function trim;
use function usleep;

class StdioTransport implements McpTransportInterface
{
    /**
     * The variables a server inherits from the application, as in the official MCP SDKs (Linux
     * and macOS, then Windows): anything else, credentials included, is passed through `env`.
     */
    protected const INHERITED_ENV = [
        'HOME', 'LOGNAME', 'PATH', 'SHELL', 'TERM', 'USER',
        'APPDATA', 'HOMEDRIVE', 'HOMEPATH', 'LOCALAPPDATA', 'PROCESSOR_ARCHITECTURE', 'PROGRAMFILES',
        'SYSTEMDRIVE', 'SYSTEMROOT', 'TEMP', 'USERNAME', 'USERPROFILE',
    ];

    /**
     * How long a server gets to exit once its stdin closes, and again after SIGTERM, as in the official MCP SDKs.
     */
    protected const EXIT_GRACE_SECONDS = 2.0;

    protected const SIGKILL = 9;

    /**
     * @var null|resource|false $process
     */
    private mixed $process = null;

    /**
     * @var null|array<int, resource|false> $pipes
     */
    private ?array $pipes = null;

    /**
     * Bytes read from stdout that do not yet complete a message.
     */
    protected string $buffer = '';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(protected array $config)
    {
    }

    /**
     * @throws McpException
     */
    public function connect(): void
    {
        $descriptorSpec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"],   // stderr
        ];

        $command = $this->config['command'];
        $args = $this->config['args'] ?? [];
        $env = $this->config['env'] ?? [];

        $fullEnv = array_merge($this->inheritedEnv(), $env);

        // Started directly, not through a shell: stopping the process stops the server itself,
        // and a path with spaces or shell syntax in the command is taken literally
        $this->process = @proc_open(
            [$command, ...$args],
            $descriptorSpec,
            $this->pipes,
            null,
            $fullEnv
        );

        if (!is_resource($this->process)) {
            throw new McpException("Failed to start the MCP server \"{$command}\": " . (error_get_last()['message'] ?? 'unknown error'));
        }

        stream_set_write_buffer($this->pipes[0], 0);
        stream_set_read_buffer($this->pipes[1], 0);

        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $error = stream_get_contents($this->pipes[2]);
            throw new McpException("Process failed to start: " . $error);
        }

        // receive() polls stdout for messages and drains stderr.
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        $this->buffer = '';
    }

    /**
     * @return array<string, string>
     */
    protected function inheritedEnv(): array
    {
        $inherited = [];

        // getenv() finds a name in any case on Windows, where PATH is often spelled Path
        foreach (static::INHERITED_ENV as $name) {
            $value = getenv($name);

            if ($value !== false) {
                $inherited[$name] = $value;
            }
        }

        return $inherited;
    }

    /**
     * @param array<string, mixed> $data
     * @throws McpException
     */
    public function send(array $data): void
    {
        if (!is_resource($this->process)) {
            throw new McpException("Process is not running");
        }

        $status = proc_get_status($this->process);
        if (!$status['running']) {
            throw new McpSessionLostException("MCP server process is not running");
        }

        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new McpException("Failed to encode request data to JSON");
        }

        $bytesWritten = fwrite($this->pipes[0], $jsonData . "\n");
        if ($bytesWritten === false || $bytesWritten < strlen($jsonData) + 1) {
            throw new McpException("Failed to write complete request to MCP server");
        }

        fflush($this->pipes[0]);
    }

    /**
     * @return array<string, mixed>
     * @throws McpException
     */
    public function receive(): array
    {
        if (!is_resource($this->process)) {
            throw new McpException("Process is not running");
        }

        $startTime = microtime(true);
        $timeout = (float) ($this->config['timeout'] ?? 30);

        while (microtime(true) - $startTime < $timeout) {
            // Messages are newline-delimited: one is complete once its newline arrives.
            $newline = strpos($this->buffer, "\n");
            if ($newline !== false) {
                $line = trim(substr($this->buffer, 0, $newline));
                $this->buffer = substr($this->buffer, $newline + 1);

                // Servers print banners and logs to stdout despite the spec: like the official SDKs, skip what is not a message
                $message = $line !== '' ? json_decode($line, true, 64) : null;
                if (is_array($message)) {
                    return $message;
                }

                continue;
            }

            $this->discardStderr();

            $chunk = fread($this->pipes[1], 8192);
            if ($chunk !== false && $chunk !== '') {
                $this->buffer .= $chunk;
                continue;
            }

            if (!proc_get_status($this->process)['running']) {
                throw new McpException("MCP server process has terminated unexpectedly.");
            }

            usleep(10000); // avoid busy waiting
        }

        throw new McpException("Timeout waiting for response from MCP server");
    }

    /**
     * A server writing to a full, unread stderr pipe blocks.
     */
    protected function discardStderr(): void
    {
        do {
            $chunk = fread($this->pipes[2], 8192);
        } while ($chunk !== false && $chunk !== '');
    }

    /**
     * Newline-delimited JSON has no envelope to carry the version: only HTTP echoes it as a header.
     */
    public function setProtocolVersion(string $version): void
    {
    }

    public function disconnect(): void
    {
        if (is_resource($this->process)) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            // The MCP shutdown: the closed stdin asks the server to exit, then SIGTERM, then SIGKILL,
            // so proc_close(), which waits for the process, cannot hang on a server that ignores both
            if (!$this->exitsWithin(static::EXIT_GRACE_SECONDS) && function_exists('proc_terminate')) {
                proc_terminate($this->process);

                if (!$this->exitsWithin(static::EXIT_GRACE_SECONDS)) {
                    proc_terminate($this->process, self::SIGKILL);
                }
            }

            proc_close($this->process);
            $this->process = null;
            $this->pipes = null;
        }
    }

    /**
     * The process state changes over time: each call asks again.
     *
     * @phpstan-impure
     */
    protected function exitsWithin(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (proc_get_status($this->process)['running']) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(10000);
        }

        return true;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
