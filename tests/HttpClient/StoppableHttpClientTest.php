<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Closure;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\HttpClient\StoppableStream;
use NeuronAI\HttpClient\StreamInterface;
use PHPUnit\Framework\TestCase;

class StoppableHttpClientTest extends TestCase
{
    public function test_requests_pass_through_untouched(): void
    {
        $response = new HttpResponse(200, '{"ok":true}');
        $client = new StoppableHttpClient($this->client($response, $this->stream(static function (): void {
        })), static fn (): bool => true);

        $this->assertSame($response, $client->request(HttpRequest::get('https://example.com')));
    }

    public function test_a_stream_reads_through_until_the_application_stops_it(): void
    {
        $closed = 0;
        $inner = $this->stream(function () use (&$closed): void {
            $closed++;
        });
        $stop = false;
        $asked = 0;
        $stream = (new StoppableHttpClient($this->client(new HttpResponse(200, ''), $inner), function () use (&$stop, &$asked): bool {
            $asked++;
            return $stop;
        }))->stream(HttpRequest::get('https://example.com'));

        $this->assertInstanceOf(StoppableStream::class, $stream);
        $this->assertFalse($stream->eof());
        $this->assertSame('data', $stream->read(4));
        $this->assertSame("line\n", $stream->readLine());
        $this->assertFalse($stream->stopped());

        $stop = true;

        // Stopping ends the stream and closes the connection, so the vendor stops generating.
        $this->assertTrue($stream->eof());
        $this->assertTrue($stream->stopped());
        $this->assertSame(1, $closed);

        // Once stopped, the stream stays ended without asking the application again.
        $this->assertTrue($stream->eof());
        $this->assertSame(2, $asked);
    }

    protected function client(HttpResponse $response, StreamInterface $stream): HttpClientInterface
    {
        return new class ($response, $stream) implements HttpClientInterface {
            public function __construct(protected HttpResponse $response, protected StreamInterface $stream)
            {
            }

            public function request(HttpRequest $request): HttpResponse
            {
                return $this->response;
            }

            public function stream(HttpRequest $request): StreamInterface
            {
                return $this->stream;
            }
        };
    }

    /**
     * An endless stream that reports every close.
     *
     * @param Closure(): void $onClose
     */
    protected function stream(Closure $onClose): StreamInterface
    {
        return new class ($onClose) implements StreamInterface {
            public function __construct(protected Closure $onClose)
            {
            }

            public function eof(): bool
            {
                return false;
            }

            public function read(int $length): string
            {
                return 'data';
            }

            public function readLine(): string
            {
                return "line\n";
            }

            public function close(): void
            {
                ($this->onClose)();
            }
        };
    }
}
