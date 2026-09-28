<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Closure;
use InvalidArgumentException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function parse_url;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_URL_PORT;

/**
 * Guards the HTTP clients against hostile input and hostile servers. Every
 * client carries provider credentials, so none may be steered into leaking them.
 */
class HttpClientSecurityTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{Closure(): HttpClientInterface}>
     */
    public static function clients(): iterable
    {
        yield 'curl' => [static fn (): HttpClientInterface => new CurlHttpClient()];
        yield 'guzzle' => [static fn (): HttpClientInterface => new GuzzleHttpClient()];
        yield 'amp' => [static fn (): HttpClientInterface => new AmpHttpClient()];
    }

    /**
     * @return iterable<string, array{Closure(): HttpClientInterface, array<string, string>}>
     */
    public static function injectedHeaders(): iterable
    {
        foreach (self::clients() as $name => [$makeClient]) {
            yield "{$name}, CRLF in a value" => [$makeClient, ['X-Tenant' => "acme\r\nX-Injected: yes"]];
            yield "{$name}, bare LF in a value" => [$makeClient, ['X-Tenant' => "acme\nX-Injected: yes"]];
            yield "{$name}, CRLF in a name" => [$makeClient, ["X-Tenant: acme\r\nX-Injected" => 'yes']];
        }
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     * @param array<string, string> $headers
     */
    #[DataProvider('injectedHeaders')]
    public function test_line_breaks_in_a_header_cannot_inject_headers(Closure $makeClient, array $headers): void
    {
        $request = HttpRequest::get(static::$baseUri . '/headers', $headers);

        try {
            $received = $makeClient()->request($request)->json();
        } catch (HttpException|InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertArrayNotHasKey('x-injected', $received);
    }

    /**
     * Providers authenticate through Authorization and through custom headers (x-api-key,
     * api-key, x-goog-api-key), set on the request or as client defaults.
     *
     * @return iterable<string, array{Closure(): HttpClientInterface, array<string, string>, bool}>
     */
    public static function credentialsMeetingARedirect(): iterable
    {
        $clients = [
            'curl' => static fn (array $defaults): HttpClientInterface => new CurlHttpClient($defaults),
            'guzzle' => static fn (array $defaults): HttpClientInterface => new GuzzleHttpClient($defaults),
            'amp' => static fn (array $defaults): HttpClientInterface => new AmpHttpClient($defaults),
        ];

        foreach ($clients as $name => $make) {
            foreach (['request' => false, 'stream' => true] as $call => $streamed) {
                yield "{$name} {$call}, Authorization" => [static fn (): HttpClientInterface => $make([]), ['Authorization' => 'Bearer provider-secret'], $streamed];
                yield "{$name} {$call}, x-api-key" => [static fn (): HttpClientInterface => $make([]), ['x-api-key' => 'provider-secret'], $streamed];
                yield "{$name} {$call}, client default x-api-key" => [static fn (): HttpClientInterface => $make(['x-api-key' => 'provider-secret']), [], $streamed];
            }
        }
    }

    /**
     * The other origin records what it receives: refused, or followed without them,
     * the credentials must never reach it.
     *
     * @param Closure(): HttpClientInterface $makeClient
     * @param array<string, string> $headers
     */
    #[DataProvider('credentialsMeetingARedirect')]
    public function test_credentials_never_reach_another_origin_on_redirect(Closure $makeClient, array $headers, bool $streamed): void
    {
        $record = sys_get_temp_dir() . '/neuron-redirect-' . bin2hex(random_bytes(6));
        $request = HttpRequest::get(static::$baseUri . '/redirect-to-other-host?record=' . basename($record), $headers);

        try {
            $client = $makeClient();
            $streamed ? $client->stream($request)->read(1024) : $client->request($request);
        } catch (HttpException $refused) {
            $this->assertNull($refused->response);
        } finally {
            $received = is_file($record) ? (string) file_get_contents($record) : '';
            @unlink($record);
        }

        $this->assertStringNotContainsString('provider-secret', $received);
    }

    /**
     * @return iterable<string, array{Closure(): HttpClientInterface, bool}>
     */
    public static function clientsRefusingRedirectsToAnotherOrigin(): iterable
    {
        yield 'curl request' => [static fn (): HttpClientInterface => new CurlHttpClient(), false];
        yield 'curl stream' => [static fn (): HttpClientInterface => new CurlHttpClient(), true];
        yield 'guzzle request' => [static fn (): HttpClientInterface => new GuzzleHttpClient(), false];
        yield 'guzzle stream' => [static fn (): HttpClientInterface => new GuzzleHttpClient(), true];
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clientsRefusingRedirectsToAnotherOrigin')]
    public function test_a_redirect_to_another_origin_is_refused_naming_its_target(Closure $makeClient, bool $streamed): void
    {
        $request = HttpRequest::get(static::$baseUri . '/redirect-to-other-host');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('refused a redirect to another origin: http://localhost:' . $this->port() . '/headers');

        $streamed ? $makeClient()->stream($request) : $makeClient()->request($request);
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clients')]
    public function test_a_redirect_within_the_origin_is_followed(Closure $makeClient): void
    {
        $this->assertSame(['status' => 'success'], $makeClient()->request(HttpRequest::get(static::$baseUri . '/redirect'))->json());
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clients')]
    public function test_a_redirect_loop_ends_in_a_network_error(Closure $makeClient): void
    {
        try {
            $makeClient()->request(HttpRequest::get(static::$baseUri . '/redirect-loop'));
            $this->fail('A redirect loop must end in an exception');
        } catch (HttpException $exception) {
            // The last redirect is not an error status to report
            $this->assertNull($exception->response);
        }
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clients')]
    public function test_a_file_url_is_refused_instead_of_reading_local_files(Closure $makeClient): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'neuron-secret');
        file_put_contents($secret, 'local secret');

        try {
            $makeClient()->request(HttpRequest::get('file://' . $secret));
            $this->fail('A file:// URL must not be served by an HTTP client');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringNotContainsString('local secret', $exception->getMessage());
        } finally {
            unlink($secret);
        }
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clients')]
    public function test_a_streamed_file_url_is_refused_instead_of_reading_local_files(Closure $makeClient): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'neuron-secret');
        file_put_contents($secret, 'local secret');

        try {
            $stream = $makeClient()->stream(HttpRequest::get('file://' . $secret));
            $this->fail('A file:// URL must not be streamed by an HTTP client: ' . $stream->read(1024));
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringNotContainsString('local secret', $exception->getMessage());
        } finally {
            unlink($secret);
        }
    }

    protected function port(): string
    {
        return (string) parse_url(static::$baseUri, PHP_URL_PORT);
    }
}
