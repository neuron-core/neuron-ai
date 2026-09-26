<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Closure;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function parse_url;

use const PHP_URL_PORT;

/**
 * Providers authenticate through custom headers (x-api-key, api-key, x-goog-api-key, Api-Key):
 * a redirect to another origin must never carry them there, whether it is refused or followed without them.
 */
class RedirectCredentialLeakTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{Closure(): HttpClientInterface, array<string, string>}>
     */
    public static function clients(): iterable
    {
        yield 'curl, request header' => [static fn (): HttpClientInterface => new CurlHttpClient(), ['x-api-key' => 'provider-secret']];
        yield 'curl, client header' => [static fn (): HttpClientInterface => new CurlHttpClient(['x-api-key' => 'provider-secret']), []];
        yield 'guzzle, request header' => [static fn (): HttpClientInterface => new GuzzleHttpClient(), ['x-api-key' => 'provider-secret']];
        yield 'guzzle, client header' => [static fn (): HttpClientInterface => new GuzzleHttpClient(['x-api-key' => 'provider-secret']), []];
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     * @param array<string, string> $headers
     */
    #[DataProvider('clients')]
    public function test_request_does_not_forward_credential_headers_to_another_origin(Closure $makeClient, array $headers): void
    {
        $request = HttpRequest::get(static::$baseUri . '/redirect-to-other-host', $headers);

        try {
            $received = $makeClient()->request($request)->json();
        } catch (HttpException $refused) {
            $this->assertNull($refused->response);
            return;
        }

        $this->assertSame('localhost:' . parse_url(static::$baseUri, PHP_URL_PORT), $received['host']);
        $this->assertArrayNotHasKey('x-api-key', $received);
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     * @param array<string, string> $headers
     */
    #[DataProvider('clients')]
    public function test_stream_does_not_forward_credential_headers_to_another_origin(Closure $makeClient, array $headers): void
    {
        $request = HttpRequest::get(static::$baseUri . '/redirect-to-other-host', $headers);

        try {
            $stream = $makeClient()->stream($request);
        } catch (HttpException $refused) {
            $this->assertNull($refused->response);
            return;
        }

        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(1024);
        }
        $received = json_decode($body, true);

        $this->assertSame('localhost:' . parse_url(static::$baseUri, PHP_URL_PORT), $received['host']);
        $this->assertArrayNotHasKey('x-api-key', $received);
    }
}
