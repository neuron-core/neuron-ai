<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\CurlHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function fclose;
use function file_put_contents;
use function fopen;
use function fsockopen;
use function is_resource;
use function json_decode;
use function microtime;
use function proc_open;
use function proc_terminate;
use function random_int;
use function rtrim;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

/**
 * Exercises the real curl stack against PHP's built-in server, including
 * the property no in-process mock can verify: SSE chunks arriving
 * incrementally instead of buffered.
 */
class CurlHttpClientTest extends TestCase
{
    protected static string $baseUri;

    /**
     * @var resource
     */
    protected static $serverProcess;

    public static function setUpBeforeClass(): void
    {
        $port = random_int(49152, 65000);
        static::$baseUri = "http://127.0.0.1:{$port}";

        $process = proc_open(
            ['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/server.php'],
            [2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PHP_CLI_SERVER_WORKERS' => '2'],
        );

        if (!is_resource($process)) {
            static::fail('Failed to start the built-in PHP server fixture');
        }

        static::$serverProcess = $process;
        static::waitForServer($port);
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(static::$serverProcess);
    }

    protected static function waitForServer(int $port): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

            if ($socket !== false) {
                fclose($socket);
                return;
            }

            usleep(50_000);
        }

        static::fail('The built-in PHP server fixture never became reachable');
    }

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
            $this->assertStringContainsString('HTTP 422 error', $exception->getMessage());
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
            $this->assertStringContainsString('Network error', $exception->getMessage());
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

        $stream = $client->stream(HttpRequest::get(static::$baseUri . '/sse'));
        $stream->close();

        // The body is a live stream, so the hook sees status and headers with an empty body.
        $this->assertNotNull($observed);
        $this->assertEquals(200, $observed[0]);
        $this->assertEquals('', $observed[1]);
        $this->assertStringContainsString('text/event-stream', (string) $observed[2]);
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
}
