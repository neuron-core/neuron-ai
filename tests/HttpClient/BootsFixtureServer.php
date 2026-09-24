<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use function fclose;
use function fsockopen;
use function is_resource;
use function proc_open;
use function proc_terminate;
use function random_int;
use function usleep;

/**
 * Boots PHP's built-in server on fixtures/server.php so a test class can
 * exercise a real HTTP stack. A test class boots another fixture by
 * overriding serverCommand().
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
        $port = random_int(49152, 65000);
        static::$baseUri = "http://127.0.0.1:{$port}";

        $process = proc_open(
            static::serverCommand($port),
            [2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '2'],
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

    public static function tearDownAfterClass(): void
    {
        proc_terminate(static::$serverProcess);
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
