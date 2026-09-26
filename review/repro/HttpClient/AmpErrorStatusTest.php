<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmpErrorStatusTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return array<string, array{string}>
     */
    public static function transferMethods(): array
    {
        return ['request' => ['request'], 'stream' => ['stream']];
    }

    #[DataProvider('transferMethods')]
    public function test_error_status_throws_http_exception_with_response(string $method): void
    {
        try {
            (new AmpHttpClient())->{$method}(HttpRequest::get(static::$baseUri . '/error'));
            $this->fail("{$method}() returned a 422 response instead of throwing HttpException");
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->response?->statusCode);
            $this->assertSame('{"error":"invalid input"}', $exception->response->body);
            $this->assertStringStartsWith('HTTP 422 error during GET ', $exception->getMessage());
        }
    }
}
