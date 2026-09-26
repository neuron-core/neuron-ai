<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function fwrite;
use function rewind;
use function trim;

class AmpHttpClientTest extends TestCase
{
    use BootsFixtureServer;

    public function test_array_body_is_sent_as_json(): void
    {
        $echo = (new AmpHttpClient())->request(HttpRequest::post(static::$baseUri . '/echo', ['key' => 'value', 'n' => 1]))->json();

        $this->assertSame('POST', $echo['method']);
        $this->assertSame('application/json', $echo['contentType']);
        $this->assertSame('{"key":"value","n":1}', $echo['body']);
    }

    public function test_raw_string_body_and_its_content_type_are_sent_unchanged(): void
    {
        $echo = (new AmpHttpClient())->request(
            new HttpRequest(HttpMethod::PUT, static::$baseUri . '/echo', ['Content-Type' => 'text/plain'], "raw\nbody")
        )->json();

        $this->assertSame('PUT', $echo['method']);
        $this->assertSame('text/plain', $echo['contentType']);
        $this->assertSame("raw\nbody", $echo['body']);
    }

    public function test_multipart_part_is_uploaded_with_its_filename(): void
    {
        $file = fopen('php://temp', 'w+');
        fwrite($file, 'audio bytes');
        rewind($file);

        try {
            $result = (new AmpHttpClient())->request(new HttpRequest(
                method: HttpMethod::POST,
                uri: static::$baseUri . '/multipart',
                body: ['file' => ['contents' => $file, 'filename' => 'audio.mp3'], 'model' => 'whisper-1'],
            ))->json();
        } finally {
            fclose($file);
        }

        $this->assertSame(['model' => 'whisper-1'], $result['fields']);
        $this->assertSame('audio.mp3', $result['files']['file']['name']);
        $this->assertSame('audio bytes', $result['files']['file']['content']);
    }

    public function test_relative_requests_resolve_against_the_base_uri(): void
    {
        $client = new AmpHttpClient();
        $this->assertSame($client, $client->withBaseUri(static::$baseUri . '/'));

        $this->assertSame(['status' => 'success'], $client->request(HttpRequest::get('/json'))->json());
    }

    public function test_with_headers_merges_defaults_case_insensitively(): void
    {
        $client = new AmpHttpClient(customHeaders: ['authorization' => 'Bearer old', 'X-Hook' => 'kept']);
        $this->assertSame($client, $client->withHeaders(['Authorization' => 'Bearer new']));

        $echo = $client->request(HttpRequest::get(static::$baseUri . '/echo'))->json();

        $this->assertSame('Bearer new', $echo['authorization']);
        $this->assertSame('kept', $echo['xHook']);
    }

    public function test_with_timeout_applies_to_later_requests(): void
    {
        $client = new AmpHttpClient();
        $this->assertSame($client, $client->withTimeout(0.01));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessageMatches('/^Network error during GET .*\/delay: /');

        $client->request(HttpRequest::get(static::$baseUri . '/delay'));
    }

    public function test_a_response_cut_short_throws_a_network_error(): void
    {
        try {
            (new AmpHttpClient())->request(HttpRequest::get(static::$baseUri . '/truncated'));
            $this->fail('A truncated response must not be returned as complete');
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
            $this->assertStringStartsWith('Network error during GET ' . static::$baseUri . '/truncated: ', $exception->getMessage());
            $this->assertNotNull($exception->getPrevious());
        }
    }

    public function test_stream_delivers_the_body_line_by_line(): void
    {
        $stream = (new AmpHttpClient())->stream(HttpRequest::get(static::$baseUri . '/sse'));

        $lines = [];
        while (!$stream->eof()) {
            $line = trim($stream->readLine());
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        $stream->close();

        $this->assertSame(['data: chunk0', 'data: chunk1', 'data: chunk2'], $lines);
    }

    public function test_sends_neuron_user_agent_by_default(): void
    {
        $response = (new AmpHttpClient())->request(HttpRequest::get(static::$baseUri . '/echo'));

        $this->assertEquals('neuron-ai/4.x', $response->json()['userAgent']);
    }

    public function test_custom_user_agent_replaces_the_default(): void
    {
        $client = (new AmpHttpClient())->withHeaders(['User-Agent' => 'my-app/1.0']);

        $response = $client->request(HttpRequest::get(static::$baseUri . '/echo'));

        $this->assertEquals('my-app/1.0', $response->json()['userAgent']);
    }
}
