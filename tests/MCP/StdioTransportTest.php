<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use NeuronAI\MCP\McpException;
use NeuronAI\MCP\McpSessionLostException;
use NeuronAI\MCP\StdioTransport;
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;
use Throwable;

use function class_exists;
use function explode;
use function file_get_contents;
use function function_exists;
use function getmypid;
use function in_array;
use function is_file;
use function json_encode;
use function strlen;
use function sys_get_temp_dir;
use function usleep;

use const PHP_BINARY;

class StdioTransportTest extends TestCase
{
    public function test_a_response_larger_than_one_read_arrives_whole(): void
    {
        $client = new McpClient($this->server(['descriptionBytes' => 20_000]));

        $this->assertSame(20_000, strlen($client->listTools()[0]['description']));
    }

    public function test_notifications_before_a_response_are_skipped(): void
    {
        $client = new McpClient($this->server(['notify' => true]));

        $this->assertSame('hello', $this->echo($client, 'hello')['text']);
    }

    public function test_a_server_writing_to_stderr_keeps_answering(): void
    {
        // More than a pipe buffer holds: an unread stderr would block the server.
        $client = new McpClient($this->server(['stderrBytes' => 200_000]));

        $this->assertSame('hello', $this->echo($client, 'hello')['text']);
    }

    public function test_a_server_that_exited_mid_message_is_restarted_for_the_next_request(): void
    {
        // The unterminated message of the old server must not prefix the new server's first one.
        $client = new McpClient($this->server(['exitAfter' => 2, 'partialLineOnExit' => true]));
        $first = $this->echo($client, 'first');
        usleep(200_000); // let the server finish exiting

        $second = $this->echo($client, 'second');

        $this->assertSame('second', $second['text']);
        $this->assertNotSame($first['server'], $second['server']);
    }

    public function test_forked_children_start_their_own_server(): void
    {
        if (!function_exists('pcntl_fork') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Forking requires the pcntl extension and spatie/fork.');
        }

        $client = new McpClient($this->server());
        $parent = $this->echo($client, 'parent');

        [$first, $second] = Fork::new()->run(
            fn (): array => $this->echo($client, 'first'),
            fn (): array => $this->echo($client, 'second'),
        );

        $this->assertSame('first', $first['text']);
        $this->assertSame('second', $second['text']);
        $this->assertNotContains($parent['server'], [$first['server'], $second['server']]);
        $this->assertNotSame($first['server'], $second['server']);
        // The children left the parent's session intact.
        $this->assertSame($parent['server'], $this->echo($client, 'parent')['server']);
    }

    public function test_arguments_reach_the_server_verbatim_without_shell_interpretation(): void
    {
        $marker = sys_get_temp_dir() . '/neuron-mcp-injected-' . getmypid();
        $args = ["\$(touch {$marker})", "; touch {$marker}", "`touch {$marker}`", "it's", '"quoted"', 'with space', '*', '', 'naïve 世界', '--flag=a&b|c'];

        $client = new McpClient($this->server([], $args));
        $result = $client->callTool('echo', ['value' => 'x'])['result'];

        $this->assertSame($args, $result['args']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_the_configured_environment_reaches_the_server(): void
    {
        $client = new McpClient(['env' => ['NEURON_MCP_FIXTURE' => 'from config']] + $this->server());

        $this->assertSame('from config', $client->callTool('echo', ['value' => 'x'])['result']['env']);
    }

    public function test_multibyte_payloads_round_trip(): void
    {
        $client = new McpClient($this->server());

        $this->assertSame('héllo 世界 🚀 "quoted" \\ new\nline', $this->echo($client, 'héllo 世界 🚀 "quoted" \\ new\nline')['text']);
    }

    public function test_a_message_written_in_parts_is_reassembled(): void
    {
        $client = new McpClient($this->server(['splitWrites' => true, 'notify' => true]));

        $this->assertSame('split', $this->echo($client, 'split')['text']);
    }

    public function test_messages_arriving_in_one_read_are_delivered_one_at_a_time(): void
    {
        $client = new McpClient($this->server(['batched' => true]));

        $this->assertSame('first', $this->echo($client, 'first')['text']);
        $this->assertSame('second', $this->echo($client, 'second')['text']);
    }

    public function test_blank_lines_and_crlf_terminators_are_skipped(): void
    {
        $client = new McpClient($this->server(['blankLines' => true, 'notify' => true]));

        $this->assertSame('crlf', $this->echo($client, 'crlf')['text']);
    }

    public function test_a_server_dying_mid_request_fails_it_and_the_next_request_gets_a_new_server(): void
    {
        // Requests: initialize, the first call, then the call the server dies on.
        $client = new McpClient($this->server(['exitBeforeAnswering' => 3]));
        $first = $this->echo($client, 'first');

        try {
            $client->callTool('echo', ['value' => 'lost']);
            $this->fail('A request the server may have received must not be sent again');
        } catch (McpException $exception) {
            $this->assertNotInstanceOf(McpSessionLostException::class, $exception);
            $this->assertSame('MCP server process has terminated unexpectedly.', $exception->getMessage());
        }

        $next = $this->echo($client, 'next');
        $this->assertSame('next', $next['text']);
        $this->assertNotSame($first['server'], $next['server']);
    }

    public function test_ending_the_session_stops_the_server(): void
    {
        $client = new McpClient($this->server());
        $server = $this->echo($client, 'hello')['server'];
        if (!is_file("/proc/{$server}/stat")) {
            $this->markTestSkipped('Reading process states requires procfs.');
        }
        $this->assertTrue($this->isRunning($server));

        unset($client);

        for ($attempt = 0; $attempt < 100 && $this->isRunning($server); $attempt++) {
            usleep(10_000);
        }
        $this->assertFalse($this->isRunning($server), 'The MCP server outlived its session');
    }

    public function test_a_transport_that_is_not_connected_refuses_to_exchange_messages(): void
    {
        $transport = new StdioTransport($this->server());

        $send = function () use ($transport): void {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        };

        foreach ([$send, $transport->receive(...)] as $exchange) {
            try {
                $exchange();
                $this->fail('Expected McpException was not thrown');
            } catch (McpException $exception) {
                $this->assertNotInstanceOf(McpSessionLostException::class, $exception);
                $this->assertSame('Process is not running', $exception->getMessage());
            }
        }
    }

    public function test_disconnect_is_idempotent_and_ends_the_exchange(): void
    {
        $transport = new StdioTransport($this->server());
        $transport->connect();

        $transport->disconnect();
        $transport->disconnect();

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Process is not running');
        $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    }

    public function test_a_command_that_cannot_run_fails_the_handshake(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessageMatches('/^(Process failed to start: |MCP server process has terminated unexpectedly\.)/');

        new McpClient(['command' => '/nonexistent/neuron-mcp-server']);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $extraArgs
     * @return array<string, mixed>
     */
    protected function server(array $options = [], array $extraArgs = []): array
    {
        return ['command' => PHP_BINARY, 'args' => [__DIR__ . '/fixtures/stdio_server.php', json_encode($options), ...$extraArgs]];
    }

    /**
     * An exited process nobody reaped yet (a zombie) is not running.
     *
     * @phpstan-impure
     */
    protected function isRunning(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        return $stat !== false && !in_array(explode(' ', $stat)[2] ?? '', ['Z', 'X'], true);
    }

    /**
     * @return array{text: string, server: ?int}
     */
    protected function echo(McpClient $client, string $value): array
    {
        try {
            $result = $client->callTool('echo', ['value' => $value])['result'];
        } catch (Throwable $e) {
            // Reported through the assertions: a child that threw would only return nothing.
            return ['text' => $e::class . ': ' . $e->getMessage(), 'server' => null];
        }

        return ['text' => $result['content'][0]['text'], 'server' => $result['server']];
    }
}
