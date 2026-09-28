<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A connection that drops mid-response must never pass for a complete answer.
 */
class TruncatedResponseTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{class-string<HttpClientInterface>, bool}>
     */
    public static function clients(): iterable
    {
        foreach ([CurlHttpClient::class, GuzzleHttpClient::class, AmpHttpClient::class] as $class) {
            yield $class . ' buffered' => [$class, false];
            yield $class . ' streamed' => [$class, true];
        }
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_a_response_cut_short_throws_a_network_error(string $class, bool $stream): void
    {
        $request = HttpRequest::get(static::$baseUri . '/truncated');

        try {
            if ($stream) {
                $body = (new $class())->stream($request);
                while (!$body->eof()) {
                    $body->read(8192);
                }
            } else {
                (new $class())->request($request);
            }
            $this->fail('A response cut short must not pass for a complete one');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringStartsWith('Network error during GET ' . static::$baseUri . '/truncated: ', $exception->getMessage());
        }
    }
}
