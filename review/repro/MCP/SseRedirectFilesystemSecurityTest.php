<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\TestCase;

use function fclose;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrchr;
use function substr;
use function usleep;

class SseRedirectFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $base;

    protected function setUp(): void
    {
        $this->base = $this->createSandbox('neuron_sse_security');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->base);
    }

    public function test_a_redirect_to_another_host_never_receives_the_credentials(): void
    {
        file_put_contents($this->base . '/router.php', <<<'PHP'
            <?php
            if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/sse') {
                file_put_contents(__DIR__ . '/origin.json', json_encode(getallheaders()));
                http_response_code(302);
                header('Location: http://localhost:' . $_SERVER['SERVER_PORT'] . '/capture');
                return;
            }
            file_put_contents(__DIR__ . '/captured.json', json_encode(getallheaders()));
            header('Content-Type: text/event-stream');
            echo "event: endpoint\ndata: /messages\n\n";
            PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $server = proc_open(['php', '-S', "127.0.0.1:{$port}", $this->base . '/router.php'], [2 => ['pipe', 'w']], $pipes);
        usleep(300000);

        $error = null;
        try {
            (new SseHttpTransport([
                'url' => "http://127.0.0.1:{$port}/sse",
                'token' => 'mcp-secret',
                'headers' => ['X-Api-Key' => 'tenant-key'],
                'timeout' => 2,
            ]))->connect();
        } catch (McpException $e) {
            $error = $e->getMessage();
        } finally {
            proc_terminate($server);
            proc_close($server);
        }

        $this->assertFileExists($this->base . '/origin.json', 'origin server was never reached');
        $this->assertSame('SSE connection failed: HTTP/1.1 302 Found', $error);
        $captured = is_file($this->base . '/captured.json') ? (string) file_get_contents($this->base . '/captured.json') : '';
        $this->assertStringNotContainsString('mcp-secret', $captured);
        $this->assertStringNotContainsString('tenant-key', $captured);
    }
}
