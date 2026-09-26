<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TruncatedStreamTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{class-string<HttpClientInterface>}>
     */
    public static function clients(): iterable
    {
        yield 'guzzle' => [GuzzleHttpClient::class];
        yield 'amp' => [AmpHttpClient::class];
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_a_stream_cut_short_throws_instead_of_ending_early(string $class): void
    {
        $this->expectException(HttpException::class);

        $stream = (new $class())->stream(HttpRequest::get(static::$baseUri . '/truncated'));
        while (!$stream->eof()) {
            $stream->read(8192);
        }
    }

    public function test_guzzle_reports_a_truncated_response_as_a_network_error(): void
    {
        try {
            (new GuzzleHttpClient())->request(HttpRequest::get(static::$baseUri . '/truncated'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertStringStartsWith('Network error during GET', $exception->getMessage());
            $this->assertNull($exception->response);
        }
    }
}
