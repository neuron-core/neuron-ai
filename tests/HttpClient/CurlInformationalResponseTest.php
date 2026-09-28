<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;

use const PHP_BINARY;

/**
 * CDNs send "103 Early Hints" ahead of the real response: the final status is the
 * one that follows it, on the streaming path as on the buffered one.
 */
class CurlInformationalResponseTest extends TestCase
{
    use BootsFixtureServer;

    public function test_stream_throws_for_an_error_status_that_follows_early_hints(): void
    {
        $observed = [];
        $client = (new CurlHttpClient())
            ->onResponse(function (HttpResponse $response) use (&$observed): void {
                $observed[] = $response->statusCode;
            });

        try {
            $client->stream(HttpRequest::get(static::$baseUri . '/'));
            $this->fail('A 500 final response must be thrown as HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->response?->statusCode);
            $this->assertSame('boom', $exception->response->body);
        } finally {
            $this->assertSame([500], $observed);
        }
    }

    public function test_request_reports_the_final_status_after_early_hints(): void
    {
        try {
            (new CurlHttpClient())->request(HttpRequest::get(static::$baseUri . '/'));
            $this->fail('A 500 final response must be thrown as HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(500, $exception->response?->statusCode);
        }
    }

    protected static function serverCommand(int $port): array
    {
        return [PHP_BINARY, __DIR__ . '/fixtures/early_hints_server.php', (string) $port];
    }
}
