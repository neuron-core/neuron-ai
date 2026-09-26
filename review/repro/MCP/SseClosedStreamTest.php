<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\Tests\HttpClient\BootsFixtureServer;
use PHPUnit\Framework\TestCase;

use function microtime;

// Place in tests/MCP (uses fixtures/sse_server.php).
class SseClosedStreamTest extends TestCase
{
    use BootsFixtureServer;

    public function test_a_stream_closed_by_the_server_fails_receive_at_once(): void
    {
        $transport = new SseHttpTransport(['url' => static::$baseUri . '/sse?close=1', 'timeout' => 3]);
        $transport->connect();
        $started = microtime(true);

        try {
            $transport->receive();
            $this->fail('Expected McpException was not thrown');
        } catch (McpException $exception) {
            $this->assertSame('SSE stream closed by server', $exception->getMessage());
            $this->assertLessThan(1.0, microtime(true) - $started);
        }
    }

    protected static function serverCommand(int $port): array
    {
        return ['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/sse_server.php'];
    }

    protected static function serverEnvironment(): array
    {
        return ['PHP_CLI_SERVER_WORKERS' => '4'];
    }
}
