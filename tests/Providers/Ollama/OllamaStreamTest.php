<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Ollama;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use NeuronAI\Tests\Tools\Stub\ToolStub;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

use function array_map;
use function array_shift;
use function implode;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function str_split;

use const JSON_THROW_ON_ERROR;

class OllamaStreamTest extends TestCase
{
    use RecordsHttpRequests;
    use ConsumesProviderStreams;

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    protected static function ndjson(array $lines): string
    {
        return implode("\n", array_map(static fn (array $line): string => json_encode($line, JSON_THROW_ON_ERROR), $lines))."\n";
    }

    /**
     * A response body delivering one network fragment per read, like a socket.
     *
     * @param list<string> $fragments
     */
    protected static function fragmentedBody(array $fragments): StreamInterface
    {
        return FnStream::decorate(Utils::streamFor(''), [
            'read' => static function (int $length) use (&$fragments): string {
                return array_shift($fragments) ?? '';
            },
            'eof' => static function () use (&$fragments): bool {
                return $fragments === [];
            },
        ]);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    protected static function line(array $message, bool $done = false): array
    {
        return ['model' => 'llama3.2', 'message' => ['role' => 'assistant', 'content' => '', ...$message], 'done' => $done];
    }

    protected function provider(string|StreamInterface $body): Ollama
    {
        $provider = new Ollama('http://localhost:11434/api/', 'llama3.2', ['options' => ['num_ctx' => 4096]], $this->recordingClient(new Response(200, body: $body)));
        $provider->setTools([new ToolStub('lookup', 'Look it up')]);

        return $provider;
    }

    public function test_stream_request_targets_the_chat_endpoint_with_streaming_enabled(): void
    {
        $provider = $this->provider(self::ndjson([self::line(['content' => 'ok'], true)]));
        $provider->systemPrompt('Be brief');

        $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['POST http://localhost:11434/api/chat'], $this->sentTargets());
        $this->assertTrue($body['stream']);
        $this->assertSame(['num_ctx' => 4096], $body['options']);
        $this->assertSame([['role' => 'system', 'content' => 'Be brief'], ['role' => 'user', 'content' => 'Hi']], $body['messages']);
        $this->assertSame('lookup', $body['tools'][0]['function']['name']);
    }

    public function test_json_lines_split_across_network_reads_are_reassembled(): void
    {
        $body = self::ndjson([
            self::line(['content' => 'Hel']),
            self::line(['content' => 'lo — ünïcode 🚀']),
            ['model' => 'llama3.2', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 12, 'eval_count' => 5],
        ]);
        $provider = $this->provider(self::fragmentedBody(str_split($body, 7)));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Hel', 'lo — ünïcode 🚀'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(AssistantMessage::class, $message);
        $this->assertSame('Hello — ünïcode 🚀', $message->getContent());
        $this->assertSame([12, 5], [$message->getUsage()->inputTokens, $message->getUsage()->outputTokens]);
        foreach ($chunks as $chunk) {
            $this->assertSame($message->getId(), $chunk->messageId);
        }
    }

    public function test_blank_lines_and_non_assistant_lines_are_ignored(): void
    {
        $body = "\n".json_encode(['message' => ['role' => 'user', 'content' => 'echo']])."\n\n"
            .self::ndjson([self::line(['content' => 'real'], true)]);

        [$chunks, $message] = $this->consumeStream($this->provider($body)->stream(new UserMessage('Hi')));

        $this->assertSame(['real'], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertSame('real', $message->getContent());
    }

    public function test_tool_calls_line_returns_tool_call_message_with_prior_text(): void
    {
        $provider = $this->provider(self::ndjson([
            self::line(['content' => 'Let me check. ']),
            self::line(['tool_calls' => [
                ['function' => ['name' => 'lookup', 'arguments' => ['q' => 'rome']]],
                ['function' => ['name' => 'lookup', 'arguments' => ['q' => 'oslo']]],
            ]]),
            self::line(['content' => 'never read'], true),
        ]));

        [$chunks, $message] = $this->consumeStream($provider->stream(new UserMessage('Weather?')));

        $this->assertSame(['Let me check. '], $this->contentsOf(TextChunk::class, $chunks));
        $this->assertInstanceOf(ToolCallMessage::class, $message);
        $this->assertSame('Let me check. ', $message->getContent());
        $calls = $message->getToolCalls();
        $this->assertSame([['q' => 'rome'], ['q' => 'oslo']], [$calls[0]->getInputs(), $calls[1]->getInputs()]);
        $this->assertSame('Look it up', $calls[0]->getDescription());
        $this->assertNotSame($calls[0]->getCallId(), $calls[1]->getCallId());
    }

    public function test_streamed_tool_call_for_an_unregistered_tool_is_rejected(): void
    {
        $provider = $this->provider(self::ndjson([
            self::line(['tool_calls' => [['function' => ['name' => 'rm', 'arguments' => ['path' => '/']]]]]),
        ]));

        $stream = $provider->stream(new UserMessage('Go'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('The model is asking for a non-existing tool: rm.');
        iterator_to_array($stream);
    }
}
