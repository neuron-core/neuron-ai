<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A caller that stops reading early, to cancel a streamed answer, closes the stream:
 * from then on it has ended on every client, and the connection is not read again.
 */
class StreamCloseTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{class-string<HttpClientInterface>}>
     */
    public static function clients(): iterable
    {
        yield 'curl' => [CurlHttpClient::class];
        yield 'guzzle' => [GuzzleHttpClient::class];
        yield 'amp' => [AmpHttpClient::class];
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_a_closed_stream_has_ended(string $class): void
    {
        $stream = (new $class())->stream(HttpRequest::get(static::$baseUri . '/sse'));
        $this->assertSame("data: chunk0\n", $stream->readLine());

        $stream->close();

        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(8192));
        $this->assertSame('', $stream->readLine());
    }
}
