<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Closure;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class GuzzleStreamTest extends TestCase
{
    public function test_read_line_returns_lines_with_their_terminator(): void
    {
        $stream = $this->stream("first\nsecond\r\n\nlast");

        $this->assertSame("first\n", $stream->readLine());
        $this->assertSame("second\r\n", $stream->readLine());
        $this->assertSame("\n", $stream->readLine());
        $this->assertSame('last', $stream->readLine());
        $this->assertSame('', $stream->readLine());
        $this->assertTrue($stream->eof());
    }

    public function test_read_line_spans_several_internal_chunks(): void
    {
        // Longer than the 512-byte chunks readLine() pulls, with a multibyte character on the boundary.
        $line = str_repeat('a', 511) . 'é' . str_repeat('b', 1000) . "\n";
        $stream = $this->stream($line . "next\n");

        $this->assertSame($line, $stream->readLine());
        $this->assertSame("next\n", $stream->readLine());
    }

    public function test_bytes_read_ahead_by_read_line_are_served_to_read_first(): void
    {
        $stream = $this->stream("header\nbody-bytes");
        $stream->readLine();

        $this->assertSame('body', $stream->read(4));
        $this->assertSame('-bytes', $stream->read(100));
        $this->assertSame('', $stream->read(100));
    }

    public function test_eof_waits_until_read_ahead_bytes_are_consumed(): void
    {
        $stream = $this->stream("one\ntwo");
        $stream->readLine();

        // The underlying stream is exhausted, but "two" is still buffered.
        $this->assertFalse($stream->eof());
        $this->assertSame('two', $stream->readLine());
        $this->assertTrue($stream->eof());
    }

    public function test_close_closes_the_underlying_stream(): void
    {
        $psrStream = Utils::streamFor("one\ntwo\n");
        $stream = new GuzzleStream($psrStream, HttpRequest::get('https://example.com/sse'));
        $stream->readLine();

        $stream->close();

        $this->assertFalse($psrStream->isReadable());
    }

    /**
     * @return iterable<string, array{Closure(StreamInterface): string}>
     */
    public static function readers(): iterable
    {
        yield 'read' => [static fn (StreamInterface $stream): string => $stream->read(8192)];
        yield 'readLine' => [static fn (StreamInterface $stream): string => $stream->readLine()];
    }

    /**
     * @param Closure(StreamInterface): string $read
     */
    #[DataProvider('readers')]
    public function test_a_body_ending_before_its_content_length_throws_a_network_error(Closure $read): void
    {
        $stream = $this->stream("data: chunk0\n", 1000);

        try {
            while (!$stream->eof()) {
                $read($stream);
            }
            $this->fail('A body cut short must not pass for a complete one');
        } catch (HttpException $exception) {
            $this->assertSame(
                'Network error during GET https://example.com/sse: Response body ended after 13 of the 1000 bytes declared by Content-Length',
                $exception->getMessage(),
            );
            $this->assertNull($exception->response);
        }
    }

    public function test_a_connection_closing_after_the_last_read_throws_a_network_error(): void
    {
        // A socket reports its end only once the close arrives, which may be after the last bytes were read.
        $endChecks = 0;
        $psrStream = FnStream::decorate(Utils::streamFor('partial'), [
            'eof' => static function () use (&$endChecks): bool {
                return $endChecks++ > 0;
            },
        ]);
        $stream = new GuzzleStream($psrStream, HttpRequest::get('https://example.com/sse'), 1000);

        $this->assertSame('partial', $stream->read(8192));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Response body ended after 7 of the 1000 bytes declared by Content-Length');

        $stream->eof();
    }

    /**
     * @param Closure(StreamInterface): string $read
     */
    #[DataProvider('readers')]
    public function test_a_body_matching_its_content_length_ends_normally(Closure $read): void
    {
        $stream = $this->stream("data: chunk0\n", 13);

        $body = '';
        while (!$stream->eof()) {
            $body .= $read($stream);
        }

        $this->assertSame("data: chunk0\n", $body);
    }

    protected function stream(string $body, ?int $contentLength = null): GuzzleStream
    {
        return new GuzzleStream(Utils::streamFor($body), HttpRequest::get('https://example.com/sse'), $contentLength);
    }
}
