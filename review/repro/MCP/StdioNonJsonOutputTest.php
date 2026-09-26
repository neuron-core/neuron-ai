<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function var_export;

use const PHP_BINARY;

class StdioNonJsonOutputTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function strayStdoutLines(): array
    {
        return [
            'startup banner' => ['Weather MCP server v1.0 listening on stdio'],
            'bare version number' => ['1.0'],
        ];
    }

    #[DataProvider('strayStdoutLines')]
    public function test_a_stray_stdout_line_is_skipped_and_the_session_stays_usable(string $strayLine): void
    {
        $server = tempnam(sys_get_temp_dir(), 'neuron-mcp-banner-');
        file_put_contents($server, '<?php
            echo ' . var_export($strayLine . "\n", true) . ';
            while (($line = fgets(STDIN)) !== false) {
                $request = json_decode($line, true);
                if (isset($request["id"])) {
                    fwrite(STDOUT, json_encode(["jsonrpc" => "2.0", "id" => $request["id"], "result" => ["tools" => [["name" => "forecast", "inputSchema" => ["type" => "object"]]]]]) . "\n");
                }
            }
        ');

        try {
            $client = new McpClient(['command' => PHP_BINARY, 'args' => [$server]]);

            $this->assertSame([['name' => 'forecast', 'inputSchema' => ['type' => 'object']]], $client->listTools());
        } finally {
            unset($client);
            unlink($server);
        }
    }
}
