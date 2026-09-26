<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\StreamableHttpTransport;
use NeuronAI\Tests\MCP\Stub\ScriptedHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamableScalarBodyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function nonMessageBodies(): iterable
    {
        yield 'null' => ['null'];
        yield 'number' => ['42'];
        yield 'string' => ['"ok"'];
        yield 'boolean' => ['true'];
        yield 'sse scalar payload' => ["event: message\ndata: 42\n\n"];
    }

    #[DataProvider('nonMessageBodies')]
    public function test_a_json_body_that_is_not_a_message_is_rejected(string $body): void
    {
        $transport = new StreamableHttpTransport(['url' => 'https://mcp.example.com/mcp'], new ScriptedHttpClient(new HttpResponse(200, $body)));
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Invalid JSON response');

        $transport->receive();
    }
}
