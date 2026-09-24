<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use NeuronAI\MCP\McpClient;
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;
use Throwable;

use function class_exists;
use function function_exists;
use function json_encode;
use function strlen;
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

    public function test_a_server_that_exited_is_restarted_for_the_next_request(): void
    {
        $client = new McpClient($this->server(['exitAfter' => 2]));
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

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function server(array $options = []): array
    {
        return ['command' => PHP_BINARY, 'args' => [__DIR__ . '/fixtures/stdio_server.php', json_encode($options)]];
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
