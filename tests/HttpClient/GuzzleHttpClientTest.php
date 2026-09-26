<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

use function basename;
use function count;
use function fclose;
use function file_put_contents;
use function fopen;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class GuzzleHttpClientTest extends TestCase
{
    use RecordsHttpRequests;

    public function test_request_with_json_body(): void
    {
        $client = $this->recordingClient(new Response(200, ['X-Request-Id' => 'r-1'], json_encode(['status' => 'success'])));

        $response = $client->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/api/endpoint',
            body: ['key' => 'value', 'text' => 'café'],
        ));

        $this->assertSame(200, $response->statusCode);
        $this->assertSame(['status' => 'success'], $response->json());
        $this->assertSame('r-1', $response->header('x-request-id'));

        $sent = $this->lastSentRequest();
        $this->assertSame('POST', $sent->getMethod());
        $this->assertSame('https://example.com/api/endpoint', (string) $sent->getUri());
        $this->assertSame('application/json', $sent->getHeaderLine('Content-Type'));
        $this->assertSame(['key' => 'value', 'text' => 'café'], json_decode((string) $sent->getBody(), true));
    }

    public function test_raw_string_body_is_sent_unchanged(): void
    {
        $client = $this->recordingClient(new Response(200));

        $client->request(new HttpRequest(HttpMethod::POST, 'https://example.com/raw', ['Content-Type' => 'text/plain'], 'raw payload'));

        $sent = $this->lastSentRequest();
        $this->assertSame('raw payload', (string) $sent->getBody());
        $this->assertSame('text/plain', $sent->getHeaderLine('Content-Type'));
    }

    public function test_request_with_multipart_body(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'neuron');
        file_put_contents($tmpFile, 'test file content');
        $fileResource = fopen($tmpFile, 'r');

        try {
            $this->recordingClient(new Response(200))->request(new HttpRequest(
                method: HttpMethod::POST,
                uri: 'https://example.com/upload',
                body: [
                    'file' => $fileResource,
                    'model' => 'whisper-1',
                ],
            ));

            $sent = $this->lastSentRequest();
            $body = (string) $sent->getBody();
        } finally {
            fclose($fileResource);
            unlink($tmpFile);
        }

        $this->assertStringStartsWith('multipart/form-data; boundary=', $sent->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('name="file"; filename="' . basename($tmpFile) . '"', $body);
        $this->assertStringContainsString("\r\n\r\ntest file content\r\n", $body);
        $this->assertStringContainsString("name=\"model\"\r\n\r\nwhisper-1\r\n", $body);
    }

    public function test_multipart_part_keeps_its_filename_and_headers(): void
    {
        $file = fopen('php://temp', 'w+');

        $this->recordingClient(new Response(200))->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/upload',
            body: ['file' => ['contents' => $file, 'filename' => 'speech.mp3', 'headers' => ['Content-Type' => 'audio/mpeg']]],
        ));

        $body = (string) $this->lastSentRequest()->getBody();
        fclose($file);

        $this->assertStringContainsString('name="file"; filename="speech.mp3"', $body);
        $this->assertStringContainsString('Content-Type: audio/mpeg', $body);
    }

    public function test_multipart_part_already_in_guzzle_format_is_passed_through(): void
    {
        $file = fopen('php://temp', 'w+');

        $this->recordingClient(new Response(200))->request(new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/upload',
            body: ['ignored-key' => ['name' => 'document', 'contents' => $file, 'filename' => 'report.pdf']],
        ));

        $body = (string) $this->lastSentRequest()->getBody();
        fclose($file);

        $this->assertStringContainsString('name="document"; filename="report.pdf"', $body);
        $this->assertStringNotContainsString('ignored-key', $body);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function uriResolutions(): iterable
    {
        yield 'relative path' => ['https://api.example.com/v1', 'chat', 'https://api.example.com/v1/chat'];
        yield 'leading slash' => ['https://api.example.com/v1', '/chat', 'https://api.example.com/v1/chat'];
        yield 'trailing slash on base' => ['https://api.example.com/v1/', 'chat', 'https://api.example.com/v1/chat'];
        yield 'query string kept' => ['https://api.example.com/v1', 'search?q=a%20b&page=2', 'https://api.example.com/v1/search?q=a%20b&page=2'];
        yield 'empty path' => ['https://api.example.com/v1', '', 'https://api.example.com/v1'];
        yield 'absolute https wins' => ['https://api.example.com/v1', 'https://other.example.com/x', 'https://other.example.com/x'];
        yield 'absolute scheme is case insensitive' => ['https://api.example.com/v1', 'HTTP://other.example.com/x', 'http://other.example.com/x'];
        yield 'no base uri' => ['', 'https://api.example.com/v1/chat', 'https://api.example.com/v1/chat'];
    }

    #[DataProvider('uriResolutions')]
    public function test_request_uri_is_resolved_against_the_base_uri(string $baseUri, string $uri, string $expected): void
    {
        $client = $this->recordingClient(new Response(200));
        $this->assertSame($client, $client->withBaseUri($baseUri));

        $client->request(HttpRequest::get($uri));

        $this->assertSame($expected, (string) $this->lastSentRequest()->getUri());
    }

    public function test_with_headers_merges_defaults_case_insensitively(): void
    {
        $client = new GuzzleHttpClient(customHeaders: ['authorization' => 'Bearer old', 'X-Tenant' => 'acme'], handler: $this->recordingStack(new Response(200)));
        $this->assertSame($client, $client->withHeaders(['Authorization' => 'Bearer new']));

        $client->request(HttpRequest::get('https://example.com/api'));

        $sent = $this->lastSentRequest();
        $this->assertSame(['Bearer new'], $sent->getHeader('Authorization'));
        $this->assertSame('acme', $sent->getHeaderLine('X-Tenant'));
    }

    public function test_with_timeout_applies_to_later_requests(): void
    {
        $client = new GuzzleHttpClient(timeout: 5.0, connectTimeout: 2.5, handler: $this->recordingStack(new Response(200), new Response(200)));
        $this->assertSame($client, $client->withTimeout(60.0));

        $client->request(HttpRequest::get('https://example.com/api'));
        $client->request(new HttpRequest(HttpMethod::GET, 'https://example.com/api', timeout: 1.5));

        $this->assertSame(60.0, $this->sentRequests[0]['options']['timeout']);
        $this->assertSame(2.5, $this->sentRequests[0]['options']['connect_timeout']);
        $this->assertSame(1.5, $this->sentRequests[1]['options']['timeout']);
    }

    public function test_client_options_reach_the_handler(): void
    {
        $client = new GuzzleHttpClient(handler: $this->recordingStack(new Response(200)), options: ['verify' => '/etc/ssl/custom-ca.pem']);

        $client->request(HttpRequest::get('https://example.com/api'));

        $this->assertSame('/etc/ssl/custom-ca.pem', $this->sentRequests[0]['options']['verify']);
    }

    public function test_stream_reads_the_body_line_by_line(): void
    {
        $client = $this->recordingClient(new Response(200, ['Content-Type' => 'text/event-stream'], "data: one\n\ndata: two\n\n"));

        $stream = $client->stream(HttpRequest::get('https://example.com/sse'));

        $lines = [];
        while (!$stream->eof()) {
            $lines[] = $stream->readLine();
        }
        $stream->close();

        $this->assertSame(["data: one\n", "\n", "data: two\n", "\n"], $lines);
        $this->assertTrue($this->sentRequests[0]['options']['stream']);
    }

    public function test_stream_error_status_throws_http_exception_with_response(): void
    {
        $client = $this->recordingClient(new Response(429, ['Retry-After' => '7'], '{"error":"rate limited"}'));

        try {
            $client->stream(HttpRequest::get('https://example.com/sse'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->response?->statusCode);
            $this->assertSame('7', $exception->response->header('retry-after'));
            $this->assertSame('HTTP 429 error during GET https://example.com/sse: {"error":"rate limited"}', $exception->getMessage());
        }
    }

    public function test_http_error_includes_response_body(): void
    {
        $errorBody = json_encode(['error' => ['message' => 'Invalid API key']]);
        $client = $this->recordingClient(new Response(401, ['Content-Type' => 'application/json'], $errorBody));
        $request = HttpRequest::get('https://example.com/api/test');

        try {
            $client->request($request);
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertSame("HTTP 401 error during GET https://example.com/api/test: {$errorBody}", $exception->getMessage());
            $this->assertSame($request, $exception->request);
            $this->assertSame(401, $exception->response?->statusCode);
            $this->assertSame($errorBody, $exception->response->body);
            $this->assertInstanceOf(RequestException::class, $exception->getPrevious());
        }
    }

    public function test_network_error(): void
    {
        $client = new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
            new ConnectException('Connection refused', new PsrRequest('GET', 'https://example.com/api/test')),
        ])));

        try {
            $client->request(HttpRequest::get('https://example.com/api/test'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertSame('Network error during GET https://example.com/api/test: Connection refused', $exception->getMessage());
            $this->assertNull($exception->response);
            $this->assertInstanceOf(ConnectException::class, $exception->getPrevious());
        }
    }

    public function test_request_exception_without_response(): void
    {
        $client = new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
            new RequestException('Request timed out', new PsrRequest('GET', 'https://example.com/api/test')),
        ])));

        try {
            $client->request(HttpRequest::get('https://example.com/api/test'));
            $this->fail('Expected HttpException was not thrown');
        } catch (HttpException $exception) {
            $this->assertSame('Network error during GET https://example.com/api/test: Request timed out', $exception->getMessage());
            $this->assertNull($exception->response);
        }
    }

    public function test_sends_neuron_user_agent_by_default(): void
    {
        $this->recordingClient(new Response(200))->request(HttpRequest::get('https://example.com/api'));

        $this->assertSame('neuron-ai/4.x', $this->lastSentRequest()->getHeaderLine('User-Agent'));
    }

    public function test_custom_user_agent_replaces_the_default(): void
    {
        $client = $this->recordingClient(new Response(200))->withHeaders(['user-agent' => 'my-app/1.0']);

        $client->request(HttpRequest::get('https://example.com/api'));

        $this->assertSame(['my-app/1.0'], $this->lastSentRequest()->getHeader('User-Agent'));
    }

    protected function recordingStack(Response ...$responses): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sentRequests));

        return $stack;
    }

    protected function lastSentRequest(): RequestInterface
    {
        return $this->sentRequests[count($this->sentRequests) - 1]['request'];
    }
}
