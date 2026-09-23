<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function fclose;
use function fopen;
use function fwrite;
use function rewind;
use function is_resource;

use const CURLOPT_HTTPHEADER;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;

class RequestConfigurationTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{class-string<CurlHttpClient|GuzzleHttpClient|AmpHttpClient>, bool}>
     */
    public static function clients(): iterable
    {
        foreach ([CurlHttpClient::class, GuzzleHttpClient::class, AmpHttpClient::class] as $class) {
            yield $class . ' buffered' => [$class, false];
            yield $class . ' streamed' => [$class, true];
        }
    }

    /**
     * @param class-string<CurlHttpClient|GuzzleHttpClient|AmpHttpClient> $class
     */
    #[DataProvider('clients')]
    public function test_request_configuration_overrides_client_defaults(string $class, bool $stream): void
    {
        $client = (new $class(customHeaders: [
            'authorization' => 'client-secret',
            'CONTENT-TYPE' => 'text/plain',
            'X-Hook' => 'retained',
        ]))->withBaseUri(static::$baseUri . '/unused');

        $request = new HttpRequest(
            HttpMethod::POST,
            static::$baseUri . '/echo',
            ['Authorization' => 'request-secret', 'Content-Type' => 'application/vnd.test+json'],
            ['value' => true],
        );

        $echo = json_decode($this->send($client, $request, $stream), true);

        self::assertSame('request-secret', $echo['authorization']);
        self::assertSame('application/vnd.test+json', $echo['contentType']);
        self::assertSame('retained', $echo['xHook']);

        $client->withBaseUri(static::$baseUri);
        $echo = json_decode($this->send($client, HttpRequest::get('echo'), $stream), true);
        self::assertSame('client-secret', $echo['authorization']);
    }

    /**
     * @param class-string<CurlHttpClient|GuzzleHttpClient|AmpHttpClient> $class
     */
    #[DataProvider('clients')]
    public function test_request_timeout_overrides_client_without_changing_it(string $class, bool $stream): void
    {
        $client = new $class(timeout: 0.01);
        $request = new HttpRequest(HttpMethod::GET, static::$baseUri . '/delay', timeout: 2.0);
        self::assertSame('done', $this->send($client, $request->withHeaders(['X-Test' => 'value']), $stream));

        $this->expectException(HttpException::class);
        $this->send($client, HttpRequest::get(static::$baseUri . '/delay'), $stream);
    }

    /**
     * @param class-string<CurlHttpClient|GuzzleHttpClient|AmpHttpClient> $class
     */
    #[DataProvider('clients')]
    public function test_request_can_shorten_timeout(string $class, bool $stream): void
    {
        $client = new $class(timeout: 2.0);
        $request = new HttpRequest(HttpMethod::GET, static::$baseUri . '/delay', timeout: 0.01);

        $this->expectException(HttpException::class);
        $this->send($client, $request, $stream);
    }

    /**
     * @param class-string<CurlHttpClient|GuzzleHttpClient|AmpHttpClient> $class
     */
    #[DataProvider('clients')]
    public function test_multipart_requests_use_request_configuration(string $class, bool $stream): void
    {
        $client = (new $class(customHeaders: ['authorization' => 'client-secret'], timeout: 0.01))
            ->withBaseUri(static::$baseUri . '/unused');

        foreach (['/multipart', '/delay'] as $path) {
            $file = fopen('php://temp', 'w+');
            fwrite($file, 'audio bytes');
            rewind($file);

            try {
                $request = new HttpRequest(
                    HttpMethod::POST,
                    static::$baseUri . $path,
                    ['Authorization' => 'request-secret'],
                    ['file' => ['contents' => $file, 'filename' => 'audio.mp3']],
                    timeout: 2.0,
                );
                $body = $this->send($client, $request, $stream);
                if ($path === '/multipart') {
                    $echo = json_decode($body, true);
                    self::assertSame('request-secret', $echo['authorization']);
                    self::assertSame('audio bytes', $echo['files']['file']['content']);
                } else {
                    self::assertSame('done', $body);
                }
            } finally {
                if (is_resource($file)) {
                    fclose($file);
                }
            }
        }
    }

    public function test_raw_curl_options_cannot_replace_request_destination_or_headers(): void
    {
        $client = new CurlHttpClient(curlOptions: [
            CURLOPT_URL => static::$baseUri . '/error',
            CURLOPT_HTTPHEADER => ['Authorization: raw-secret'],
            CURLOPT_TIMEOUT_MS => 1,
        ]);
        $request = new HttpRequest(HttpMethod::GET, static::$baseUri . '/echo', ['Authorization' => 'request-secret'], timeout: 2.0);

        foreach ([false, true] as $stream) {
            self::assertSame('done', $this->send($client, new HttpRequest(HttpMethod::GET, static::$baseUri . '/delay', timeout: 2.0), $stream));
            $echo = json_decode($this->send($client, $request, $stream), true);
            self::assertSame('request-secret', $echo['authorization']);
        }
    }

    protected function send(HttpClientInterface $client, HttpRequest $request, bool $stream): string
    {
        if (!$stream) {
            return $client->request($request)->body;
        }

        $response = $client->stream($request);
        try {
            $body = '';
            while (!$response->eof()) {
                $body .= $response->read(8192);
            }
            return $body;
        } finally {
            $response->close();
        }
    }
}
