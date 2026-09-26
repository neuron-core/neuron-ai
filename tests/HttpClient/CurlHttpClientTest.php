<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function file_put_contents;
use function fopen;
use function implode;
use function json_decode;
use function microtime;
use function rtrim;
use function sprintf;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const CURLOPT_USERAGENT;

/**
 * Exercises the real curl stack against PHP's built-in server, including
 * the property no in-process mock can verify: SSE chunks arriving
 * incrementally instead of buffered.
 */
class CurlHttpClientTest extends TestCase
{
    use BootsFixtureServer;

    public function test_get_request(): void
    {
        $response = (new CurlHttpClient())->request(
            HttpRequest::get(static::$baseUri . '/json')
        );

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals(['status' => 'success'], $response->json());
        $this->assertEquals(['neuron'], $response->headers['X-Custom-Header']);
    }

    public function test_json_body_and_default_headers(): void
    {
        $client = (new CurlHttpClient(customHeaders: ['Authorization' => 'Bearer token-123']))
            ->withBaseUri(static::$baseUri);

        $response = $client->request(HttpRequest::post('echo', ['key' => 'value']));

        $echo = $response->json();

        $this->assertEquals('POST', $echo['method']);
        $this->assertEquals('application/json', $echo['contentType']);
        $this->assertEquals('Bearer token-123', $echo['authorization']);
        $this->assertEquals('{"key":"value"}', $echo['body']);
    }

    public function test_expect_continue_is_suppressed(): void
    {
        $response = (new CurlHttpClient())->request(
            HttpRequest::post(static::$baseUri . '/echo', ['payload' => str_repeat('x', 2048)])
        );

        $this->assertEquals('', $response->json()['expect']);
    }

    public function test_sends_neuron_user_agent_by_default(): void
    {
        $response = (new CurlHttpClient())->request(HttpRequest::get(static::$baseUri . '/echo'));

        $this->assertEquals('neuron-ai/4.x', $response->json()['userAgent']);
    }

    public function test_custom_user_agent_replaces_the_default(): void
    {
        $client = new CurlHttpClient(customHeaders: ['User-Agent' => 'my-app/1.0']);

        $response = $client->request(HttpRequest::get(static::$baseUri . '/echo'));

        $this->assertEquals('my-app/1.0', $response->json()['userAgent']);
    }

    public function test_multipart_body_with_resource(): void
    {
        $tmpFile = rtrim(sys_get_temp_dir(), '/') . '/curl-client-test.mp3';
        file_put_contents($tmpFile, 'fake audio bytes');
        $fileResource = fopen($tmpFile, 'r');

        try {
            $response = (new CurlHttpClient())->request(new HttpRequest(
                method: HttpMethod::POST,
                uri: static::$baseUri . '/multipart',
                body: [
                    'file' => $fileResource,
                    'model' => 'whisper-1',
                ],
            ));

            $result = $response->json();

            $this->assertEquals('whisper-1', $result['fields']['model']);
            // The upload filename must come from the underlying file: APIs
            // like OpenAI transcription infer the format from the extension.
            $this->assertEquals('curl-client-test.mp3', $result['files']['file']['name']);
            $this->assertEquals('fake audio bytes', $result['files']['file']['content']);
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_error_status_throws_http_exception_with_response(): void
    {
        try {
            (new CurlHttpClient())->request(HttpRequest::get(static::$baseUri . '/error'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertNotNull($exception->response);
            $this->assertEquals(422, $exception->response->statusCode);
            $this->assertEquals(['error' => 'invalid input'], $exception->response->json());
            $this->assertSame(
                'HTTP 422 error during GET ' . static::$baseUri . '/error: {"error":"invalid input"}',
                $exception->getMessage(),
            );
            $this->assertSame(static::$baseUri . '/error', $exception->request?->uri);
        }
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function transfers(): iterable
    {
        yield 'buffered' => [false];
        yield 'streamed' => [true];
    }

    #[DataProvider('transfers')]
    public function test_status_400_is_the_first_error(bool $stream): void
    {
        $client = new CurlHttpClient();
        $send = fn (int $status): string => $stream
            ? $this->drain($client->stream(HttpRequest::get(static::$baseUri . "/status?code={$status}")))
            : $client->request(HttpRequest::get(static::$baseUri . "/status?code={$status}"))->body;

        $this->assertSame('status 399', $send(399));

        try {
            $send(400);
            $this->fail('A 400 response must throw HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(400, $exception->response?->statusCode);
            $this->assertSame('status 400', $exception->response->body);
        }
    }

    public function test_network_error_throws_http_exception_without_response(): void
    {
        $client = new CurlHttpClient(timeout: 1.0, connectTimeout: 0.5);

        try {
            $client->request(HttpRequest::get('http://127.0.0.1:9/unreachable'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringStartsWith('Network error during GET http://127.0.0.1:9/unreachable: ', $exception->getMessage());
        }
    }

    public function test_timeout_throws_http_exception(): void
    {
        $client = new CurlHttpClient(timeout: 0.5);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/Network error/');

        $client->request(HttpRequest::get(static::$baseUri . '/slow'));
    }

    public function test_redirect_is_followed(): void
    {
        $response = (new CurlHttpClient())->request(
            HttpRequest::get(static::$baseUri . '/redirect')
        );

        $this->assertEquals(200, $response->statusCode);
        $this->assertEquals(['status' => 'success'], $response->json());
        // Headers must belong to the final response, not the redirect.
        $this->assertEquals(['neuron'], $response->headers['X-Custom-Header']);
    }

    public function test_stream_delivers_sse_chunks_incrementally(): void
    {
        $stream = (new CurlHttpClient())->stream(
            HttpRequest::get(static::$baseUri . '/sse')
        );

        $readTimes = [];
        $lines = [];

        while (!$stream->eof()) {
            $line = $stream->readLine();

            if (trim($line) !== '') {
                $lines[] = trim($line);
                $readTimes[] = microtime(true);
            }
        }

        $stream->close();

        $this->assertEquals(['data: chunk0', 'data: chunk1', 'data: chunk2'], $lines);

        // The server sleeps 250ms between chunks. Buffered delivery would
        // surface all lines at once (near-zero spread); live streaming
        // spreads reads across the whole transfer.
        $spread = $readTimes[2] - $readTimes[0];
        $this->assertGreaterThan(0.2, $spread, 'SSE chunks arrived buffered, not incrementally');
    }

    public function test_stream_error_status_throws_http_exception_with_body(): void
    {
        try {
            (new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/error'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertNotNull($exception->response);
            $this->assertEquals(422, $exception->response->statusCode);
            $this->assertEquals(['error' => 'invalid input'], $exception->response->json());
        }
    }

    public function test_stream_read_and_eof(): void
    {
        $stream = (new CurlHttpClient())->stream(
            HttpRequest::get(static::$baseUri . '/json')
        );

        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(8);
        }

        $stream->close();

        $this->assertEquals(['status' => 'success'], json_decode($body, true));
    }

    public function test_on_request_hooks_modify_the_request_in_order(): void
    {
        $client = (new CurlHttpClient())
            ->onRequest(fn (HttpRequest $request): HttpRequest => $request->withHeaders(['X-Hook' => 'first']))
            ->onRequest(fn (HttpRequest $request): HttpRequest => $request->withHeaders(['X-Hook' => 'second']));

        $response = $client->request(HttpRequest::post(static::$baseUri . '/echo', ['key' => 'value']));

        $this->assertEquals('second', $response->json()['xHook']);
    }

    public function test_on_response_hook_observes_the_response(): void
    {
        $observed = null;

        $client = (new CurlHttpClient())->onResponse(function (HttpResponse $response, HttpRequest $request) use (&$observed): void {
            $observed = [$response->statusCode, $request->uri];
        });

        $client->request(HttpRequest::get(static::$baseUri . '/json'));

        $this->assertEquals([200, static::$baseUri . '/json'], $observed);
    }

    public function test_on_response_hook_fires_before_error_status_throws(): void
    {
        $observedStatus = null;

        $client = (new CurlHttpClient())->onResponse(function (HttpResponse $response) use (&$observedStatus): void {
            $observedStatus = $response->statusCode;
        });

        try {
            $client->request(HttpRequest::get(static::$baseUri . '/error'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException) {
            $this->assertEquals(422, $observedStatus);
        }
    }

    public function test_stream_applies_request_hooks_and_observes_headers_only(): void
    {
        $observed = null;

        $client = (new CurlHttpClient())
            ->onRequest(fn (HttpRequest $request): HttpRequest => $request->withHeaders(['X-Hook' => 'streamed']))
            ->onResponse(function (HttpResponse $response) use (&$observed): void {
                $observed = [$response->statusCode, $response->body, $response->header('Content-Type')];
            });

        $stream = $client->stream(HttpRequest::get(static::$baseUri . '/echo'));
        $echo = json_decode($this->drain($stream), true);

        $this->assertSame('streamed', $echo['xHook']);
        // The body is a live stream, so the hook sees status and headers with an empty body.
        $this->assertSame([200, '', 'application/json'], $observed);
    }

    public function test_curl_handle_is_reused_across_requests(): void
    {
        $client = (new CurlHttpClient())->withBaseUri(static::$baseUri);
        $handleProperty = new ReflectionProperty(CurlHttpClient::class, 'handle');

        $first = $client->request(HttpRequest::get('json'));
        $handleAfterFirst = $handleProperty->getValue($client);

        $second = $client->request(HttpRequest::post('echo', ['n' => 2]));
        $handleAfterSecond = $handleProperty->getValue($client);

        $this->assertEquals(200, $first->statusCode);
        $this->assertEquals(200, $second->statusCode);
        $this->assertSame($handleAfterFirst, $handleAfterSecond);
    }

    public function test_raw_string_body_and_its_content_type_are_sent_unchanged(): void
    {
        $body = "{\"jsonrpc\":\"2.0\"}\ncaf\u{e9}";

        $echo = (new CurlHttpClient())->request(
            new HttpRequest(HttpMethod::POST, static::$baseUri . '/echo', ['Content-Type' => 'text/plain'], $body)
        )->json();

        $this->assertSame($body, $echo['body']);
        $this->assertSame('text/plain', $echo['contentType']);
    }

    public function test_reused_handle_does_not_carry_a_previous_request_over(): void
    {
        $client = new CurlHttpClient();
        $client->request(new HttpRequest(HttpMethod::PUT, static::$baseUri . '/echo', ['X-Hook' => 'first'], ['key' => 'value']));

        $echo = $client->request(HttpRequest::get(static::$baseUri . '/echo'))->json();

        $this->assertSame('GET', $echo['method']);
        $this->assertSame('', $echo['xHook']);
        $this->assertSame('', $echo['body']);
        $this->assertSame('', $echo['contentType']);
    }

    public function test_with_headers_merges_defaults_case_insensitively(): void
    {
        $client = (new CurlHttpClient(customHeaders: ['authorization' => 'Bearer old', 'X-Hook' => 'kept']))
            ->withHeaders(['Authorization' => 'Bearer new']);

        $echo = $client->request(HttpRequest::get(static::$baseUri . '/echo'))->json();

        $this->assertSame('Bearer new', $echo['authorization']);
        $this->assertSame('kept', $echo['xHook']);
    }

    public function test_with_timeout_applies_to_later_requests(): void
    {
        $client = (new CurlHttpClient())->withTimeout(0.01);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/^Network error during GET .*\/delay: /');

        $client->request(HttpRequest::get(static::$baseUri . '/delay'));
    }

    public function test_raw_curl_options_override_transport_defaults(): void
    {
        $client = new CurlHttpClient(curlOptions: [CURLOPT_USERAGENT => 'raw-agent/2.0']);

        $this->assertSame('raw-agent/2.0', $client->request(HttpRequest::get(static::$baseUri . '/echo'))->json()['userAgent']);
    }

    public function test_multipart_string_part_is_uploaded_as_a_named_file_with_its_type(): void
    {
        $response = (new CurlHttpClient())->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: static::$baseUri . '/multipart',
            body: [
                'file' => ['contents' => 'col1,col2', 'filename' => 'data.csv', 'headers' => ['Content-Type' => 'text/csv']],
                'purpose' => 'batch',
            ],
        ));

        $this->assertSame(
            ['authorization' => '', 'fields' => ['purpose' => 'batch'], 'files' => ['file' => ['name' => 'data.csv', 'type' => 'text/csv', 'content' => 'col1,col2']]],
            $response->json(),
        );
    }

    public function test_multipart_part_without_filename_or_type_is_a_binary_file_named_after_its_field(): void
    {
        $response = (new CurlHttpClient())->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: static::$baseUri . '/multipart',
            body: ['document' => ['contents' => 'plain bytes']],
        ));

        $this->assertSame(
            ['document' => ['name' => 'document', 'type' => 'application/octet-stream', 'content' => 'plain bytes']],
            $response->json()['files'],
        );
    }

    public function test_request_failing_midway_throws_a_network_error(): void
    {
        try {
            (new CurlHttpClient())->request(HttpRequest::get(static::$baseUri . '/truncated'));
            $this->fail('A truncated response must not be returned as complete');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringStartsWith('Network error during GET ' . static::$baseUri . '/truncated: ', $exception->getMessage());
        }
    }

    public function test_stream_failing_midway_throws_instead_of_ending_early(): void
    {
        try {
            $this->drain((new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/truncated')));
            $this->fail('A truncated stream must not look like a complete one');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringStartsWith('Network error during GET ' . static::$baseUri . '/truncated: ', $exception->getMessage());
        }
    }

    public function test_stream_connection_failure_throws_without_response(): void
    {
        try {
            (new CurlHttpClient(connectTimeout: 0.5))->stream(HttpRequest::get('http://127.0.0.1:9/unreachable'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringStartsWith('Network error during GET http://127.0.0.1:9/unreachable: ', $exception->getMessage());
        }
    }

    public function test_stream_error_hook_sees_the_drained_body(): void
    {
        $observed = null;
        $client = (new CurlHttpClient())->onResponse(function (HttpResponse $response) use (&$observed): void {
            $observed = [$response->statusCode, $response->body];
        });

        try {
            $client->stream(HttpRequest::get(static::$baseUri . '/error'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException) {
            $this->assertSame([422, '{"error":"invalid input"}'], $observed);
        }
    }

    public function test_stream_read_line_joins_split_lines_and_returns_the_unterminated_tail(): void
    {
        $stream = (new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/lines'));

        $lines = [];
        while (!$stream->eof()) {
            $lines[] = $stream->readLine();
        }
        $stream->close();

        $this->assertSame(["alpha\n", "beta\n", 'gamma'], $lines);
    }

    public function test_stream_mixed_reads_reassemble_a_body_larger_than_the_buffer_threshold(): void
    {
        $stream = (new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/large'));

        $expected = '';
        for ($line = 0; $line < 10_000; $line++) {
            $expected .= sprintf("line-%05d\n", $line);
        }

        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->readLine();
            $body .= $stream->read(7);
        }
        $stream->close();

        $this->assertSame($expected, $body);
    }

    public function test_stream_read_never_exceeds_the_requested_length(): void
    {
        $stream = (new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/json'));

        $chunks = [];
        while (!$stream->eof()) {
            $chunks[] = $stream->read(3);
        }
        $stream->close();

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(3, strlen($chunk));
        }
        $this->assertSame('{"status":"success"}', implode('', $chunks));
    }

    public function test_closed_stream_is_at_eof_and_reads_nothing(): void
    {
        $stream = (new CurlHttpClient())->stream(HttpRequest::get(static::$baseUri . '/sse'));
        $stream->readLine();

        $stream->close();

        $this->assertTrue($stream->eof());
        $this->assertSame('', $stream->read(1024));
        $this->assertSame('', $stream->readLine());
    }

    protected function drain(StreamInterface $stream): string
    {
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
        }
        $stream->close();

        return $body;
    }
}
