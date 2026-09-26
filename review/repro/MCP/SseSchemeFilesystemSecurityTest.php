<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpException;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\Tests\Support\FileSystemSandbox;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function file_put_contents;
use function str_replace;

class SseSchemeFilesystemSecurityTest extends TestCase
{
    use FileSystemSandbox;

    protected string $base;

    protected function setUp(): void
    {
        $this->base = $this->createSandbox('neuron_sse_security');
        file_put_contents($this->base . '/stream.sse', "event: endpoint\ndata: /messages\n\n");
    }

    protected function tearDown(): void
    {
        $this->removeSandbox($this->base);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonHttpUrls(): iterable
    {
        yield 'data url' => ['data://text/plain;base64,' . base64_encode("event: endpoint\ndata: /messages\n\n")];
        yield 'local file' => ['file://{base}/stream.sse'];
        yield 'php filter' => ['php://filter/resource={base}/stream.sse'];
    }

    #[DataProvider('nonHttpUrls')]
    public function test_connect_refuses_urls_that_are_not_http(string $url): void
    {
        $transport = new SseHttpTransport(['url' => str_replace('{base}', $this->base, $url), 'timeout' => 1]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Invalid URL format');

        $transport->connect();
    }
}
