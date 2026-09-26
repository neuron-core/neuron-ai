<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use InvalidArgumentException;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;

class HttpRequestTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function urisWithLineBreaks(): iterable
    {
        yield 'carriage return' => ["https://api.example.com/v1\rHost: evil.example"];
        yield 'line feed' => ["https://api.example.com/v1\nHost: evil.example"];
        yield 'CRLF' => ["https://api.example.com/v1\r\nX-Injected: yes"];
        yield 'trailing line feed' => ["https://api.example.com/v1\n"];
    }

    #[DataProvider('urisWithLineBreaks')]
    public function test_uri_with_line_breaks_is_rejected(string $uri): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URI must not contain line breaks');

        new HttpRequest(HttpMethod::GET, $uri);
    }

    public function test_uri_with_line_breaks_is_rejected_by_the_factories(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('URI must not contain line breaks');

        HttpRequest::post("https://api.example.com/v1\r\nX-Injected: yes", ['key' => 'value']);
    }

    public function test_uri_keeps_encoded_and_multibyte_characters(): void
    {
        $uri = 'https://api.example.com/search?q=caf%C3%A9&name=' . 'naïve 世界';

        $this->assertSame($uri, HttpRequest::get($uri)->uri);
    }

    /**
     * @return iterable<string, array{HttpRequest, HttpMethod, array<string, mixed>|null}>
     */
    public static function factories(): iterable
    {
        $headers = ['X-Trace' => 'abc'];
        $body = ['key' => 'value'];

        yield 'get' => [HttpRequest::get('/resource', $headers), HttpMethod::GET, null];
        yield 'post' => [HttpRequest::post('/resource', $body, $headers), HttpMethod::POST, $body];
        yield 'put' => [HttpRequest::put('/resource', $body, $headers), HttpMethod::PUT, $body];
        yield 'patch' => [HttpRequest::patch('/resource', $body, $headers), HttpMethod::PATCH, $body];
        yield 'delete' => [HttpRequest::delete('/resource', $body, $headers), HttpMethod::DELETE, $body];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    #[DataProvider('factories')]
    public function test_factories_build_the_matching_request(HttpRequest $request, HttpMethod $method, ?array $body): void
    {
        $this->assertSame($method, $request->method);
        $this->assertSame('/resource', $request->uri);
        $this->assertSame(['X-Trace' => 'abc'], $request->headers);
        $this->assertSame($body, $request->body);
        $this->assertNull($request->timeout);
    }

    public function test_factories_default_to_an_empty_json_body(): void
    {
        $this->assertSame([], HttpRequest::post('/resource')->body);
        $this->assertSame([], HttpRequest::delete('/resource')->body);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string|null, bool}>
     */
    public static function bodies(): iterable
    {
        yield 'no body' => [null, false];
        yield 'raw string' => ['{"key":"value"}', false];
        yield 'empty array' => [[], false];
        yield 'json fields' => [['model' => 'whisper-1', 'temperature' => 0.2, 'stream' => false], false];
        yield 'nested array without contents' => [['metadata' => ['filename' => 'a.txt']], false];
        yield 'contents set to null' => [['file' => ['contents' => null]], false];
        yield 'string contents part' => [['file' => ['contents' => 'raw bytes', 'filename' => 'a.txt']], true];
        yield 'empty string contents part' => [['file' => ['contents' => '']], true];
    }

    /**
     * @param array<string, mixed>|string|null $body
     */
    #[DataProvider('bodies')]
    public function test_multipart_is_inferred_from_the_body_shape(array|string|null $body, bool $multipart): void
    {
        $request = new HttpRequest(HttpMethod::POST, '/upload', body: $body);

        $this->assertSame($multipart, $request->isMultipart());
    }

    public function test_a_body_holding_a_resource_is_multipart(): void
    {
        $file = fopen('php://memory', 'r+');

        try {
            $this->assertTrue((new HttpRequest(HttpMethod::POST, '/upload', body: ['model' => 'x', 'file' => $file]))->isMultipart());
            $this->assertTrue((new HttpRequest(HttpMethod::POST, '/upload', body: ['file' => ['contents' => $file]]))->isMultipart());
        } finally {
            fclose($file);
        }
    }

    public function test_with_headers_returns_a_new_request_and_leaves_the_original_untouched(): void
    {
        $original = new HttpRequest(HttpMethod::PUT, '/resource', ['Accept' => 'application/json'], ['key' => 'value'], 12.5);

        $modified = $original->withHeaders(['X-Trace' => 'abc']);

        $this->assertNotSame($original, $modified);
        $this->assertSame(['Accept' => 'application/json'], $original->headers);
        $this->assertSame(['Accept' => 'application/json', 'X-Trace' => 'abc'], $modified->headers);
        $this->assertSame(HttpMethod::PUT, $modified->method);
        $this->assertSame('/resource', $modified->uri);
        $this->assertSame(['key' => 'value'], $modified->body);
        $this->assertSame(12.5, $modified->timeout);
    }

    public function test_with_headers_overrides_existing_names_case_insensitively(): void
    {
        $request = HttpRequest::get('/resource', ['authorization' => 'Bearer old', 'Accept' => 'text/plain'])
            ->withHeaders(['AUTHORIZATION' => 'Bearer new']);

        // A single header survives, spelled as the override spells it: two spellings would send two values.
        $this->assertSame(['Accept' => 'text/plain', 'AUTHORIZATION' => 'Bearer new'], $request->headers);
    }

    public function test_with_headers_resolves_case_duplicates_within_one_call_to_the_last(): void
    {
        $request = HttpRequest::get('/resource')->withHeaders([
            'X-Api-Key' => 'first',
            'x-api-key' => 'second',
        ]);

        $this->assertSame(['x-api-key' => 'second'], $request->headers);
    }

    public function test_with_empty_headers_keeps_the_existing_ones(): void
    {
        $request = HttpRequest::get('/resource', ['Accept' => 'application/json'])->withHeaders([]);

        $this->assertSame(['Accept' => 'application/json'], $request->headers);
    }
}
