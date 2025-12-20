<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\HttpClient\AmpHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function fopen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function fclose;
use function file_put_contents;

class AmpHttpClientTest extends TestCase
{
    public function test_is_multipart_data_detection(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test content');
        $fileResource = fopen($tmpFile, 'r');

        $client = new AmpHttpClient();

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

    public function test_with_base_uri(): void
    {
        $client = new AmpHttpClient();
        $newClient = $client->withBaseUri('https://api.example.com');

        $this->assertInstanceOf(AmpHttpClient::class, $newClient);
        $this->assertNotSame($client, $newClient);
    }

    public function test_with_headers(): void
    {
        $client = new AmpHttpClient();
        $newClient = $client->withHeaders(['X-Custom-Header' => 'value']);

        $this->assertInstanceOf(AmpHttpClient::class, $newClient);
        $this->assertNotSame($client, $newClient);
    }

    public function test_with_timeout(): void
    {
        $client = new AmpHttpClient();
        $newClient = $client->withTimeout(60.0);

        $this->assertInstanceOf(AmpHttpClient::class, $newClient);
        $this->assertNotSame($client, $newClient);
    }

    public function test_request_json_body_structure(): void
    {
        $request = new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/api/endpoint',
            body: ['key' => 'value', 'number' => 123]
        );

        // Since we don't have a mock server for Amp, we just verify the request is created correctly
        $this->assertEquals(HttpMethod::POST, $request->method);
        $this->assertIsArray($request->body);
        $this->assertArrayHasKey('key', $request->body);
    }

    public function test_multipart_request_structure(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tmpFile, 'test file content');
        $fileResource = fopen($tmpFile, 'r');

        $request = new HttpRequest(
            method: HttpMethod::POST,
            uri: 'https://example.com/upload',
            body: [
                'file' => $fileResource,
                'name' => 'test.txt',
                'description' => 'Test file upload',
            ]
        );

        // Verify request structure
        $this->assertEquals(HttpMethod::POST, $request->method);
        $this->assertIsArray($request->body);
        $this->assertIsResource($request->body['file']);

        fclose($fileResource);
        unlink($tmpFile);
    }
}
