<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use GuzzleHttp\Psr7\Response;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Providers, vector stores and toolkits join paths built from configuration or
 * model input to a base URI that carries their credentials. Only an explicit
 * http(s) URL may leave that base: a relative URI must never become a local
 * file, another stream wrapper or another host.
 */
class RequestUriFilesystemSecurityTest extends TestCase
{
    use RecordsHttpRequests;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function relativeUris(): iterable
    {
        yield 'protocol-relative host' => ['//evil.example/steal', 'https://api.example.com/v1/evil.example/steal'];
        yield 'triple slash path' => ['///etc/passwd', 'https://api.example.com/v1/etc/passwd'];
        yield 'file url' => ['file:///etc/passwd', 'https://api.example.com/v1/file:///etc/passwd'];
        yield 'php stream wrapper' => ['php://filter/resource=/etc/passwd', 'https://api.example.com/v1/php://filter/resource=/etc/passwd'];
        yield 'ftp url' => ['ftp://evil.example/x', 'https://api.example.com/v1/ftp://evil.example/x'];
        yield 'windows share' => ['\\\\evil.example\\share', 'https://api.example.com/v1/%5C%5Cevil.example%5Cshare'];
        yield 'host disguised as credentials' => ['@evil.example/x', 'https://api.example.com/v1/@evil.example/x'];
        yield 'url behind leading whitespace' => [' https://evil.example/', 'https://api.example.com/v1/%20https://evil.example/'];
    }

    #[DataProvider('relativeUris')]
    public function test_a_relative_uri_never_leaves_the_base_uri(string $uri, string $expected): void
    {
        $client = $this->recordingClient(new Response(200))->withBaseUri('https://api.example.com/v1');

        $client->request(HttpRequest::get($uri));

        $this->assertSame(["GET {$expected}"], $this->sentTargets());
    }
}
