<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function basename;
use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function is_resource;
use function json_decode;
use function rewind;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The multipart contract every client honours: file parts reach the server
 * as files, named so that APIs inferring the format from the extension work.
 */
class MultipartBodyTest extends TestCase
{
    use BootsFixtureServer;

    /**
     * @return iterable<string, array{class-string<HttpClientInterface>}>
     */
    public static function clients(): iterable
    {
        yield 'curl' => [CurlHttpClient::class];
        yield 'guzzle' => [GuzzleHttpClient::class];
        yield 'amp' => [AmpHttpClient::class];
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_a_file_resource_is_uploaded_under_the_file_name(string $class): void
    {
        $path = tempnam(sys_get_temp_dir(), 'neuron-upload');
        file_put_contents($path, 'RIFF wave bytes');
        $file = fopen($path, 'r');

        try {
            $result = (new $class())->request(new HttpRequest(
                HttpMethod::POST,
                static::$baseUri . '/multipart',
                body: ['file' => $file, 'model' => 'whisper-1', 'temperature' => '0'],
            ))->json();
        } finally {
            $this->closeIfOpen($file);
            unlink($path);
        }

        $this->assertSame(['model' => 'whisper-1', 'temperature' => '0'], $result['fields']);
        $this->assertSame(basename($path), $result['files']['file']['name']);
        $this->assertSame('RIFF wave bytes', $result['files']['file']['content']);
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_a_resource_part_keeps_its_filename_and_content_type(string $class): void
    {
        $file = fopen('php://temp', 'w+');
        fwrite($file, 'ID3 bytes');
        rewind($file);

        try {
            $result = (new $class())->request(new HttpRequest(
                HttpMethod::POST,
                static::$baseUri . '/multipart',
                body: ['file' => ['contents' => $file, 'filename' => 'speech.mp3', 'headers' => ['Content-Type' => 'audio/mpeg']]],
            ))->json();
        } finally {
            $this->closeIfOpen($file);
        }

        $this->assertSame(['name' => 'speech.mp3', 'type' => 'audio/mpeg', 'content' => 'ID3 bytes'], $result['files']['file']);
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_a_string_part_beside_a_file_is_uploaded_as_a_named_file_with_its_type(string $class): void
    {
        $file = fopen('php://temp', 'w+');
        fwrite($file, 'RIFF wave bytes');
        rewind($file);

        try {
            $result = (new $class())->request(new HttpRequest(
                HttpMethod::POST,
                static::$baseUri . '/multipart',
                body: [
                    'audio' => ['contents' => $file, 'filename' => 'speech.wav'],
                    'file' => ['contents' => 'col1,col2', 'filename' => 'data.csv', 'headers' => ['Content-Type' => 'text/csv']],
                    'purpose' => 'batch',
                ],
            ))->json();
        } finally {
            $this->closeIfOpen($file);
        }

        $this->assertSame(['purpose' => 'batch'], $result['fields']);
        $this->assertSame(['name' => 'data.csv', 'type' => 'text/csv', 'content' => 'col1,col2'], $result['files']['file']);
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clients')]
    public function test_application_data_holding_a_contents_key_is_sent_as_json(string $class): void
    {
        $body = ['model' => 'classifier', 'state' => ['contents' => 'application data, not an upload']];

        $echo = (new $class())->request(HttpRequest::post(static::$baseUri . '/echo', $body))->json();

        $this->assertSame('application/json', $echo['contentType']);
        $this->assertSame($body, json_decode($echo['body'], true));
    }

    /**
     * Guzzle sends a part with neither a filename nor a file behind it as a plain field.
     *
     * @return iterable<string, array{class-string<HttpClientInterface>}>
     */
    public static function clientsNamingPartsAfterTheirField(): iterable
    {
        yield 'curl' => [CurlHttpClient::class];
        yield 'amp' => [AmpHttpClient::class];
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clientsNamingPartsAfterTheirField')]
    public function test_a_part_without_filename_or_type_is_a_binary_file_named_after_its_field(string $class): void
    {
        $file = fopen('php://temp', 'w+');
        fwrite($file, 'RIFF wave bytes');
        rewind($file);

        try {
            $result = (new $class())->request(new HttpRequest(
                HttpMethod::POST,
                static::$baseUri . '/multipart',
                body: ['audio' => ['contents' => $file, 'filename' => 'speech.wav'], 'document' => ['contents' => 'plain bytes']],
            ))->json();
        } finally {
            $this->closeIfOpen($file);
        }

        $this->assertSame(['name' => 'document', 'type' => 'application/octet-stream', 'content' => 'plain bytes'], $result['files']['document']);
    }

    /**
     * Guzzle closes the resources it uploads, the other clients leave them to the caller.
     *
     * @param resource|closed-resource $file
     */
    protected function closeIfOpen(mixed $file): void
    {
        if (is_resource($file)) {
            fclose($file);
        }
    }
}
