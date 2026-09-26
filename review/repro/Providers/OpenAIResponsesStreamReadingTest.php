<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\OpenAI\Responses;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use NeuronAI\Providers\OpenAI\Responses\OpenAIResponses;
use NeuronAI\Tests\Support\ConsumesProviderStreams;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function implode;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;

class OpenAIResponsesStreamReadingTest extends TestCase
{
    use ConsumesProviderStreams;

    protected function streamingClient(StreamInterface $stream): HttpClientInterface
    {
        return new class ($stream) implements HttpClientInterface {
            public function __construct(protected StreamInterface $stream)
            {
            }

            public function request(HttpRequest $request): HttpResponse
            {
                throw new RuntimeException('Not used');
            }

            public function stream(HttpRequest $request): StreamInterface
            {
                return $this->stream;
            }
        };
    }

    protected function countingStream(string $body): StreamInterface
    {
        return new class ($body) implements StreamInterface {
            public int $readCalls = 0;
            public int $readLineCalls = 0;

            public function __construct(protected string $body)
            {
            }

            public function eof(): bool
            {
                return $this->body === '';
            }

            public function read(int $length): string
            {
                $this->readCalls++;
                $chunk = substr($this->body, 0, $length);
                $this->body = substr($this->body, strlen($chunk));
                return $chunk;
            }

            public function readLine(): string
            {
                $this->readLineCalls++;
                $newline = strpos($this->body, "\n");
                $length = $newline === false ? strlen($this->body) : $newline + 1;
                $line = substr($this->body, 0, $length);
                $this->body = substr($this->body, $length);
                return $line;
            }

            public function close(): void
            {
            }
        };
    }

    public function test_stream_is_consumed_line_by_line_not_byte_by_byte(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => str_repeat('a', 10000)],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ]);
        $stream = $this->countingStream($body);
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->streamingClient($stream));

        [$chunks] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(str_repeat('a', 10000), implode('', $this->contentsOf(TextChunk::class, $chunks)));
        $this->assertLessThan(20, $stream->readCalls + $stream->readLineCalls);
    }

    public function test_text_delta_containing_done_is_not_dropped(): void
    {
        $body = self::sseBody([
            ['type' => 'response.output_item.added', 'item' => ['type' => 'message', 'id' => 'msg_1', 'content' => []]],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_1', 'delta' => 'Task DONE.'],
            ['type' => 'response.completed', 'response' => ['output' => []]],
        ]);
        $provider = new OpenAIResponses('sk-test', 'gpt-test', httpClient: $this->streamingClient($this->countingStream($body)));

        [$chunks] = $this->consumeStream($provider->stream(new UserMessage('Hi')));

        $this->assertSame(['Task DONE.'], $this->contentsOf(TextChunk::class, $chunks));
    }
}
