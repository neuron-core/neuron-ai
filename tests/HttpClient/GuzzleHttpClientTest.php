<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function fopen;
use function json_encode;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function fclose;
use function file_put_contents;

class GuzzleHttpClientTest extends TestCase
{
    public function test_request_with_json_body(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['status' => 'success'])),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new GuzzleHttpClient(handler: $handler);

        $request = new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/api/endpoint',
            body: ['key' => 'value']
        );

        $response = $client->request($request);

        $this->assertEquals(200, $response->statusCode);
        $this->assertStringContainsString('success', $response->body);
    }

    public function test_request_with_multipart_body(): void
    {
        // Create a temporary file for testing
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test file content');
        $fileResource = fopen($tmpFile, 'r');

        $mock = new MockHandler([
            new Response(200, [], json_encode(['uploaded' => true])),
        ]);

        $handler = HandlerStack::create($mock);
        $client = new GuzzleHttpClient(handler: $handler);

        $request = new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/upload',
            body: [
                'file' => $fileResource,
                'name' => 'test.txt',
                'description' => 'Test file upload',
            ]
        );

        $response = $client->request($request);

        $this->assertEquals(200, $response->statusCode);
        $this->assertStringContainsString('uploaded', $response->body);

        fclose($fileResource);
        unlink($tmpFile);
    }

    public function test_is_multipart_data_detection(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test content');
        $fileResource = fopen($tmpFile, 'r');

        $client = new GuzzleHttpClient();

        // Test with file resource - should be multipart
        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('isMultipartData');

        $isMultipart = $method->invoke($client, ['file' => $fileResource]);
        $this->assertTrue($isMultipart);

        // Test with regular array - should not be multipart
        $isMultipart = $method->invoke($client, ['key' => 'value', 'number' => 123]);
        $this->assertFalse($isMultipart);

        // Test with nested array containing resource - should be multipart
        $isMultipart = $method->invoke($client, [
            'data' => ['contents' => $fileResource],
        ]);
        $this->assertTrue($isMultipart);

        fclose($fileResource);
        unlink($tmpFile);
    }

    public function test_build_multipart_data(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test content');
        $fileResource = fopen($tmpFile, 'r');

        $client = new GuzzleHttpClient();

        $reflection = new ReflectionClass($client);
        $method = $reflection->getMethod('buildMultipartData');

        $result = $method->invoke($client, [
            'file' => $fileResource,
            'name' => 'test.txt',
            'description' => 'Test file',
        ]);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);

        // Check that all parts have required 'name' and 'contents' keys
        foreach ($result as $part) {
            $this->assertArrayHasKey('name', $part);
            $this->assertArrayHasKey('contents', $part);
        }

        fclose($fileResource);
        unlink($tmpFile);
    }

    public function test_with_base_uri(): void
    {
        $client = new GuzzleHttpClient();
        $newClient = $client->withBaseUri('https://api.example.com');

        $this->assertInstanceOf(GuzzleHttpClient::class, $newClient);
        $this->assertNotSame($client, $newClient);
    }

    public function test_with_headers(): void
    {
        $client = new GuzzleHttpClient();
        $newClient = $client->withHeaders(['X-Custom-Header' => 'value']);

        $this->assertInstanceOf(GuzzleHttpClient::class, $newClient);
        $this->assertNotSame($client, $newClient);
    }

    public function test_with_timeout(): void
    {
        $client = new GuzzleHttpClient();
        $newClient = $client->withTimeout(60.0);

        $this->assertInstanceOf(GuzzleHttpClient::class, $newClient);
        $this->assertNotSame($client, $newClient);
    }
}
