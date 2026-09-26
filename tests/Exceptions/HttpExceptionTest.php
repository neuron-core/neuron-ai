<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Exceptions;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HttpExceptionTest extends TestCase
{
    public function test_status_error_describes_the_failed_exchange(): void
    {
        $request = new HttpRequest(HttpMethod::POST, 'https://api.example.com/v1/messages', ['Authorization' => 'Bearer sk-secret']);
        $response = new HttpResponse(429, '{"error":"rate limited"}');
        $previous = new RuntimeException('transport');

        $exception = HttpException::statusError($request, $response, $previous);

        $this->assertSame('HTTP 429 error during POST https://api.example.com/v1/messages: {"error":"rate limited"}', $exception->getMessage());
        $this->assertSame($request, $exception->request);
        $this->assertSame($response, $exception->response);
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(0, $exception->getCode());
    }

    public function test_network_error_has_no_response(): void
    {
        $request = new HttpRequest(HttpMethod::GET, 'https://api.example.com/v1/models');

        $exception = HttpException::networkError($request, 'Could not resolve host');

        $this->assertSame('Network error during GET https://api.example.com/v1/models: Could not resolve host', $exception->getMessage());
        $this->assertSame($request, $exception->request);
        $this->assertNull($exception->response);
        $this->assertNull($exception->getPrevious());
    }

    public function test_messages_never_include_request_headers_or_body(): void
    {
        $request = new HttpRequest(
            HttpMethod::POST,
            'https://api.example.com/v1/messages',
            ['Authorization' => 'Bearer sk-header-secret', 'x-api-key' => 'sk-header-secret'],
            '{"api_key":"sk-body-secret"}',
        );

        foreach ([
            HttpException::statusError($request, new HttpResponse(500, 'Internal error')),
            HttpException::networkError($request, 'Connection reset'),
        ] as $exception) {
            $this->assertStringNotContainsString('sk-header-secret', $exception->getMessage());
            $this->assertStringNotContainsString('sk-body-secret', $exception->getMessage());
        }
    }
}
