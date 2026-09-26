<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Amp\ByteStream\ReadableIterableStream;
use NeuronAI\HttpClient\Amp\AmpStream;
use PHPUnit\Framework\TestCase;

class AmpStreamTest extends TestCase
{
    public function test_read_returns_at_most_the_requested_length_and_keeps_the_rest(): void
    {
        $stream = $this->stream('abcdefgh', 'ij');

        $this->assertSame('abc', $stream->read(3));
        $this->assertSame('def', $stream->read(3));
        // The remainder of a chunk is served before the next chunk is pulled.
        $this->assertSame('gh', $stream->read(3));
        $this->assertSame('ij', $stream->read(3));
    }

    public function test_eof_is_reached_only_after_the_last_chunk_is_consumed(): void
    {
        $stream = $this->stream('abc');

        $this->assertFalse($stream->eof());
        $this->assertSame('abc', $stream->read(10));
        $this->assertSame('', $stream->read(10));
        $this->assertTrue($stream->eof());
    }

    public function test_read_line_joins_lines_split_across_chunks(): void
    {
        $stream = $this->stream("data: {\"par", "tial\":true}\n\nda", "ta: [DONE]\n");

        $this->assertSame("data: {\"partial\":true}\n", $stream->readLine());
        $this->assertSame("\n", $stream->readLine());
        $this->assertSame("data: [DONE]\n", $stream->readLine());
        $this->assertSame('', $stream->readLine());
        $this->assertTrue($stream->eof());
    }

    public function test_read_line_returns_an_unterminated_last_line(): void
    {
        $stream = $this->stream("first\nlast");

        $this->assertSame("first\n", $stream->readLine());
        $this->assertSame('last', $stream->readLine());
        $this->assertTrue($stream->eof());
    }

    public function test_read_after_read_line_serves_the_buffered_bytes(): void
    {
        $stream = $this->stream("line\nrest", '-more');
        $stream->readLine();

        $this->assertSame('rest', $stream->read(100));
        $this->assertSame('-more', $stream->read(100));
    }

    public function test_close_ends_the_stream_and_drops_buffered_bytes(): void
    {
        $stream = $this->stream("one\ntwo\n");
        $stream->read(2);

        $stream->close();

        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->readLine());
    }

    protected function stream(string ...$chunks): AmpStream
    {
        return new AmpStream(new ReadableIterableStream($chunks));
    }
}
