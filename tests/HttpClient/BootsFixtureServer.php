<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use function fclose;
use function fsockopen;
use function is_resource;
use function parse_url;
use function proc_open;
use function proc_terminate;
use function stream_socket_get_name;
use function stream_socket_server;
use function usleep;

use const PHP_URL_PORT;

/**
 * Boots PHP's built-in server on fixtures/server.php so a test class can
 * exercise a real HTTP stack. A test class boots another fixture by
 * overriding serverCommand(), and sizes the worker pool through serverEnvironment().
 */
trait BootsFixtureServer
{
    protected static string $baseUri;

    /**
     * @var resource
     */
    protected static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        $port = static::freePort();
        static::$baseUri = "http://127.0.0.1:{$port}";

        $process = proc_open(
            static::serverCommand($port),
            [2 => ['pipe', 'w']],
            $pipes,
            null,
            static::serverEnvironment(),
        );

        if (!is_resource($process)) {
            self::fail('Failed to start the fixture server');
        }

        static::$serverProcess = $process;
        static::waitForServer($port);
    }

    /**
     * @return list<string>
     */
    protected static function serverCommand(int $port): array
    {
        return ['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/server.php'];
    }

    /**
     * @return array<string, string>
     */
    protected static function serverEnvironment(): array
    {
        return ['PHP_CLI_SERVER_WORKERS' => '2'];
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(static::$serverProcess);
    }

    /**
     * A port the OS just handed out: a random one may belong to a server started concurrently.
     */
    protected static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            self::fail('No free port for the fixture server');
        }
        $port = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        return $port;
    }

    protected static function waitForServer(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

            if ($socket !== false) {
                fclose($socket);
                return;
            }

            usleep(50_000);
        }

        self::fail('The fixture server never became reachable');
    }
}
