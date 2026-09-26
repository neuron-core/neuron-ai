<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Amp\ByteStream\ReadableIterableStream;
use NeuronAI\HttpClient\Amp\AmpStream;
use PHPUnit\Framework\TestCase;

class AmpStreamCloseTest extends TestCase
{
    public function test_close_closes_the_underlying_stream(): void
    {
        $body = new ReadableIterableStream(["data: one\n\n", "data: two\n\n"]);
        $stream = new AmpStream($body);
        $stream->readLine();

        $stream->close();

        $this->assertTrue($body->isClosed(), 'The response body stays open after close()');
    }
}
