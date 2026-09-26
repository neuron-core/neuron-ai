<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use PHPUnit\Framework\TestCase;
use Throwable;

use function explode;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function mkdir;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

use const PHP_BINARY;

// Place in tests/MCP (uses fixtures/stdio_server.php).
class StdioCommandShellTest extends TestCase
{
    public function test_a_command_path_containing_spaces_starts(): void
    {
        $dir = sys_get_temp_dir() . '/neuron mcp ' . getmypid();
        mkdir($dir);
        symlink(PHP_BINARY, "{$dir}/php");

        try {
            $client = new McpClient(['command' => "{$dir}/php", 'args' => [__DIR__ . '/fixtures/stdio_server.php', '{}']]);
            $this->assertSame('ok', $client->callTool('echo', ['value' => 'ok'])['result']['content'][0]['text']);
        } finally {
            unset($client);
            unlink("{$dir}/php");
            rmdir($dir);
        }
    }

    public function test_shell_syntax_in_the_command_is_not_executed(): void
    {
        $marker = sys_get_temp_dir() . '/neuron-mcp-command-injected-' . getmypid();

        try {
            new McpClient(['command' => PHP_BINARY . " -r 'touch(\"{$marker}\");' ; " . PHP_BINARY, 'args' => [__DIR__ . '/fixtures/stdio_server.php', '{}']]);
        } catch (Throwable) {
        }

        $exists = file_exists($marker);
        @unlink($marker);
        $this->assertFalse($exists, 'The command string was run through a shell');
    }

    public function test_ending_the_session_stops_a_server_that_outlives_its_stdin(): void
    {
        $script = sys_get_temp_dir() . '/neuron-mcp-lingering-' . getmypid() . '.php';
        file_put_contents($script, <<<'PHP'
            <?php
            while (($line = fgets(STDIN)) !== false) {
                $request = json_decode($line, true);
                if (isset($request['id'])) {
                    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => ['pid' => getmypid()]]) . "\n");
                }
            }
            sleep(5);
            PHP);

        try {
            $client = new McpClient(['command' => PHP_BINARY, 'args' => [$script]]);
            $pid = $client->callTool('any')['result']['pid'];
            unset($client);
            usleep(200_000);

            $stat = @file_get_contents("/proc/{$pid}/stat");
            $state = $stat === false ? 'gone' : explode(' ', $stat)[2];
            $this->assertContains($state, ['gone', 'Z', 'X'], "The MCP server is still running (state {$state})");
        } finally {
            unlink($script);
        }
    }
}
