<?php

declare(strict_types=1);

namespace NeuronAI\MCP;

use JsonException;

use function array_merge;
use function escapeshellarg;
use function fclose;
use function fflush;
use function fread;
use function function_exists;
use function fwrite;
use function getenv;
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
use function mb_strlen;
use function microtime;
use function usleep;

use const JSON_THROW_ON_ERROR;

class StdioTransport implements McpTransportInterface
{
    /**
     * @var null|resource|false $process
     */
    private mixed $process = null;

    /**
     * @var null|array<int, resource|false> $pipes
     */
    private ?array $pipes = null;

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

        $fullEnv = array_merge(getenv(), $env);

        $commandLine = $command;
        foreach ($args as $arg) {
            $commandLine .= ' ' . escapeshellarg((string) $arg);
        }

        $this->process = proc_open(
            $commandLine,
            $descriptorSpec,
            $this->pipes,
            null,
            $fullEnv
        );

        if (!is_resource($this->process)) {
            throw new McpException("Failed to start the MCP server process");
        }

        stream_set_write_buffer($this->pipes[0], 0);
        stream_set_read_buffer($this->pipes[1], 0);

        $status = proc_get_status($this->process);
        if (!$status['running']) {
            $error = stream_get_contents($this->pipes[2]);
            throw new McpException("Process failed to start: " . $error);
        }
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
            throw new McpException("MCP server process is not running");
        }

        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new McpException("Failed to encode request data to JSON");
        }

        $bytesWritten = fwrite($this->pipes[0], $jsonData . "\n");
        if ($bytesWritten === false || $bytesWritten < mb_strlen($jsonData) + 1) {
            throw new McpException("Failed to write complete request to MCP server");
        }

        fflush($this->pipes[0]);
    }

    /**
     * @return array<string, mixed>
     * @throws McpException|JsonException
     */
    public function receive(): array
    {
        if (!is_resource($this->process)) {
            throw new McpException("Process is not running");
        }

        stream_set_blocking($this->pipes[1], false);

        $response = "";
        $startTime = microtime(true);
        $timeout = 30.0;

        while (microtime(true) - $startTime < $timeout) {
            $status = proc_get_status($this->process);

            if (!$status['running']) {
                throw new McpException("MCP server process has terminated unexpectedly.");
            }

            $chunk = fread($this->pipes[1], 4096);
            if ($chunk !== false && $chunk !== '') {
                $response .= $chunk;

                $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
                if ($decoded !== null) {
                    return $decoded;
                }
            }

            usleep(10000); // avoid busy waiting
        }

        throw new McpException("Timeout waiting for response from MCP server");
    }

    public function disconnect(): void
    {
        if (is_resource($this->process)) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $status = proc_get_status($this->process);

            // Graceful shutdown: SIGTERM, then give the process 500ms before closing the handle
            if ($status['running'] && function_exists('proc_terminate')) {
                proc_terminate($this->process);
                usleep(500000);
            }

            proc_close($this->process);
            $this->process = null;
            $this->pipes = null;
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
