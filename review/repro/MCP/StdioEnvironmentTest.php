<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use PHPUnit\Framework\TestCase;

use function putenv;

use const PHP_BINARY;

// Place in tests/MCP (uses fixtures/stdio_server.php, which reports NEURON_MCP_FIXTURE).
class StdioEnvironmentTest extends TestCase
{
    public function test_the_application_secrets_are_not_handed_to_the_server(): void
    {
        putenv('NEURON_MCP_FIXTURE=sk-live-application-secret');

        try {
            $client = new McpClient(['command' => PHP_BINARY, 'args' => [__DIR__ . '/fixtures/stdio_server.php', '{}']]);
            $env = $client->callTool('echo', ['value' => 'x'])['result']['env'];
        } finally {
            putenv('NEURON_MCP_FIXTURE');
        }

        $this->assertNull($env);
    }
}
