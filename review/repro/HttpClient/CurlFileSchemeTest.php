<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

class CurlFileSchemeTest extends TestCase
{
    public function test_a_file_url_is_refused_instead_of_reading_local_files(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'neuron-secret');
        file_put_contents($secret, 'local secret');

        try {
            $response = (new CurlHttpClient())->request(HttpRequest::get('file://' . $secret));
            $this->fail("A file:// URL was served with status {$response->statusCode}: {$response->body}");
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
        } finally {
            unlink($secret);
        }
    }

    public function test_a_streamed_file_url_is_refused_instead_of_reading_local_files(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'neuron-secret');
        file_put_contents($secret, 'local secret');

        try {
            $stream = (new CurlHttpClient())->stream(HttpRequest::get('file://' . $secret));
            $body = '';
            while (!$stream->eof()) {
                $body .= $stream->read(1024);
            }
            $this->fail("A streamed file:// URL was served: {$body}");
        } catch (HttpException $exception) {
            $this->assertNull($exception->response);
        } finally {
            unlink($secret);
        }
    }
}
