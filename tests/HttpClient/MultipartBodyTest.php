<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

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
    public static function clientsNamingFilesAfterTheResource(): iterable
    {
        yield 'curl' => [CurlHttpClient::class];
        yield 'guzzle' => [GuzzleHttpClient::class];
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clientsNamingFilesAfterTheResource')]
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
     * @return iterable<string, array{class-string<HttpClientInterface>}>
     */
    public static function clientsKeepingPartHeaders(): iterable
    {
        yield 'curl' => [CurlHttpClient::class];
        yield 'guzzle' => [GuzzleHttpClient::class];
    }

    /**
     * @param class-string<HttpClientInterface> $class
     */
    #[DataProvider('clientsKeepingPartHeaders')]
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
