<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use InvalidArgumentException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurlHeaderInjectionTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function injectedHeaders(): array
    {
        return [
            'CRLF in value' => [['X-Tenant' => "acme\r\nX-Injected: yes"]],
            'bare LF in value' => [['X-Tenant' => "acme\nX-Injected: yes"]],
            'CRLF in name' => [["X-Tenant: acme\r\nX-Injected" => 'yes']],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('injectedHeaders')]
    public function test_request_rejects_line_breaks_in_headers(array $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain line breaks');

        (new CurlHttpClient())->request(HttpRequest::get(static::$baseUri . '/headers', $headers));
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('injectedHeaders')]
    public function test_stream_rejects_line_breaks_in_headers(array $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain line breaks');

        (new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/headers', $headers));
    }

    public function test_client_default_headers_with_line_breaks_are_rejected(): void
    {
        $client = new CurlHttpClient(['X-Tenant' => "acme\r\nX-Injected: yes"]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain line breaks');

        $client->request(HttpRequest::get(static::$baseUri . '/headers'));
    }

    public function test_clean_headers_still_reach_the_server(): void
    {
        $received = (new CurlHttpClient())
            ->request(HttpRequest::get(static::$baseUri . '/headers', ['X-Tenant' => 'acme: west']))
            ->json();

        $this->assertSame('acme: west', $received['x-tenant']);
    }
}
