<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use GuzzleHttp\Psr7\Utils;
use NeuronAI\HttpClient\Guzzle\GuzzleStream;
use PHPUnit\Framework\TestCase;

use function str_repeat;

class GuzzleStreamTest extends TestCase
{
    public function test_read_line_returns_lines_with_their_terminator(): void
    {
        $stream = new GuzzleStream(Utils::streamFor("first\nsecond\r\n\nlast"));

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
        $stream = new GuzzleStream(Utils::streamFor($line . "next\n"));

        $this->assertSame($line, $stream->readLine());
        $this->assertSame("next\n", $stream->readLine());
    }

    public function test_bytes_read_ahead_by_read_line_are_served_to_read_first(): void
    {
        $stream = new GuzzleStream(Utils::streamFor("header\nbody-bytes"));
        $stream->readLine();

        $this->assertSame('body', $stream->read(4));
        $this->assertSame('-bytes', $stream->read(100));
        $this->assertSame('', $stream->read(100));
    }

    public function test_eof_waits_until_read_ahead_bytes_are_consumed(): void
    {
        $stream = new GuzzleStream(Utils::streamFor("one\ntwo"));
        $stream->readLine();

        // The underlying stream is exhausted, but "two" is still buffered.
        $this->assertFalse($stream->eof());
        $this->assertSame('two', $stream->readLine());
        $this->assertTrue($stream->eof());
    }

    public function test_close_closes_the_underlying_stream(): void
    {
        $psrStream = Utils::streamFor("one\ntwo\n");
        $stream = new GuzzleStream($psrStream);
        $stream->readLine();

        $stream->close();

        $this->assertFalse($psrStream->isReadable());
    }
}
