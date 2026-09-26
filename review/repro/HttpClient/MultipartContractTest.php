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
use function rewind;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class MultipartContractTest extends TestCase
{
    use BootsFixtureServer;

    public function test_amp_uploads_a_file_resource_under_the_file_name(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'neuron-upload');
        file_put_contents($path, 'RIFF wave bytes');
        $file = fopen($path, 'r');

        try {
            $result = (new AmpHttpClient())->request(new HttpRequest(
                HttpMethod::POST,
                static::$baseUri . '/multipart',
                body: ['file' => $file, 'model' => 'whisper-1'],
            ))->json();
        } finally {
            $this->closeIfOpen($file);
            unlink($path);
        }

        $this->assertSame(['model' => 'whisper-1'], $result['fields'], 'The file was sent as a plain form field');
        $this->assertSame(['name' => basename($path), 'type' => 'application/octet-stream', 'content' => 'RIFF wave bytes'], $result['files']['file'] ?? null);
    }

    public function test_amp_keeps_the_content_type_of_a_part(): void
    {
        $file = fopen('php://temp', 'w+');
        fwrite($file, 'ID3 bytes');
        rewind($file);

        try {
            $result = (new AmpHttpClient())->request(new HttpRequest(
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
    public function test_a_string_contents_part_is_uploaded_as_a_file(string $class): void
    {
        $request = new HttpRequest(
            HttpMethod::POST,
            static::$baseUri . '/multipart',
            body: ['file' => ['contents' => 'col1,col2', 'filename' => 'data.csv', 'headers' => ['Content-Type' => 'text/csv']]],
        );
        $this->assertTrue($request->isMultipart());

        $result = (new $class())->request($request)->json();

        $this->assertSame(['name' => 'data.csv', 'type' => 'text/csv', 'content' => 'col1,col2'], $result['files']['file'] ?? null, 'The part was not uploaded as multipart');
    }

    /**
     * @param resource|closed-resource $file
     */
    protected function closeIfOpen(mixed $file): void
    {
        if (is_resource($file)) {
            fclose($file);
        }
    }
}
