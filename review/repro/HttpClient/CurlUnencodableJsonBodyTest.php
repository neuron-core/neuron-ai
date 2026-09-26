<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use InvalidArgumentException;
use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\TestCase;

class CurlUnencodableJsonBodyTest extends TestCase
{
    use BootsFixtureServer;

    public function test_request_refuses_a_body_that_cannot_be_json_encoded(): void
    {
        try {
            $echo = (new CurlHttpClient())->request(
                HttpRequest::post(static::$baseUri . '/echo', ['text' => "invalid \xB1 utf-8"])
            )->json();
        } catch (HttpException|InvalidArgumentException|JsonException) {
            $this->addToAssertionCount(1);
            return;
        }

        $this->fail("The request was sent with the body '{$echo['body']}' and Content-Type {$echo['contentType']}");
    }

    public function test_stream_refuses_a_body_that_cannot_be_json_encoded(): void
    {
        try {
            $stream = (new CurlHttpClient())->stream(
                HttpRequest::post(static::$baseUri . '/echo', ['text' => "invalid \xB1 utf-8"])
            );
        } catch (HttpException|InvalidArgumentException|JsonException) {
            $this->addToAssertionCount(1);
            return;
        }

        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(1024);
        }
        $stream->close();

        $this->fail("The streamed request was sent anyway: {$body}");
    }
}
