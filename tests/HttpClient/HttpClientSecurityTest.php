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

use function file_put_contents;
use function parse_url;
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
     * @return iterable<string, array{Closure(): HttpClientInterface}>
     */
    public static function clientsRejectingHeaderLineBreaks(): iterable
    {
        yield 'guzzle' => [static fn (): HttpClientInterface => new GuzzleHttpClient()];
        yield 'amp' => [static fn (): HttpClientInterface => new AmpHttpClient()];
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clientsRejectingHeaderLineBreaks')]
    public function test_line_breaks_in_a_header_value_cannot_inject_headers(Closure $makeClient): void
    {
        $request = HttpRequest::get(static::$baseUri . '/headers', ['X-Tenant' => "acme\r\nX-Injected: yes"]);

        try {
            $received = $makeClient()->request($request)->json();
        } catch (HttpException|InvalidArgumentException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertArrayNotHasKey('x-injected', $received);
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clients')]
    public function test_authorization_is_not_forwarded_to_another_host_on_redirect(Closure $makeClient): void
    {
        $received = $makeClient()->request(
            HttpRequest::get(static::$baseUri . '/redirect-to-other-host', ['Authorization' => 'Bearer provider-secret'])
        )->json();

        $this->assertSame('localhost:' . $this->port(), $received['host']);
        $this->assertArrayNotHasKey('authorization', $received);
    }

    /**
     * @return iterable<string, array{Closure(): HttpClientInterface}>
     */
    public static function clientsDroppingCredentialHeadersOnRedirect(): iterable
    {
        yield 'amp' => [static fn (): HttpClientInterface => new AmpHttpClient()];
    }

    /**
     * Providers authenticate through custom headers too (x-api-key, api-key, x-goog-api-key).
     *
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clientsDroppingCredentialHeadersOnRedirect')]
    public function test_custom_credential_headers_are_not_forwarded_to_another_host_on_redirect(Closure $makeClient): void
    {
        $received = $makeClient()->request(
            HttpRequest::get(static::$baseUri . '/redirect-to-other-host', ['x-api-key' => 'provider-secret'])
        )->json();

        $this->assertSame('localhost:' . $this->port(), $received['host']);
        $this->assertArrayNotHasKey('x-api-key', $received);
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clients')]
    public function test_a_redirect_loop_ends_in_an_exception(Closure $makeClient): void
    {
        $this->expectException(HttpException::class);

        $makeClient()->request(HttpRequest::get(static::$baseUri . '/redirect-loop'));
    }

    /**
     * @return iterable<string, array{Closure(): HttpClientInterface}>
     */
    public static function clientsRefusingNonHttpSchemes(): iterable
    {
        yield 'guzzle' => [static fn (): HttpClientInterface => new GuzzleHttpClient()];
        yield 'amp' => [static fn (): HttpClientInterface => new AmpHttpClient()];
    }

    /**
     * @param Closure(): HttpClientInterface $makeClient
     */
    #[DataProvider('clientsRefusingNonHttpSchemes')]
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

    protected function port(): string
    {
        return (string) parse_url(static::$baseUri, PHP_URL_PORT);
    }
}
