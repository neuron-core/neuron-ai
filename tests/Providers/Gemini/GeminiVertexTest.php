<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Gemini;

use Google\Auth\HttpHandler\HttpClientCache;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Gemini\GeminiVertex;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function base64_decode;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_verify;
use function parse_str;
use function strtr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_RSA;

/**
 * The OAuth exchange runs offline: google/auth takes its HTTP client from
 * HttpClientCache, which answers the token request with a fixed access token.
 */
class GeminiVertexTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ACCESS_TOKEN = 'ya29.vertex-access-token';
    protected const ANSWER = '{"candidates":[{"content":{"role":"model","parts":[{"text":"Hi from Vertex"}]},"finishReason":"STOP"}]}';

    protected string $credentialsPath;

    protected string $publicKey;

    /** @var array<int, array{request: RequestInterface, response: ResponseInterface|null, error: mixed, options: array<mixed>}> */
    protected array $tokenRequests = [];

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'neuron_vertex_');
        file_put_contents($this->credentialsPath, json_encode([
            'type' => 'service_account',
            'project_id' => 'my-project',
            'private_key_id' => 'key-id',
            'private_key' => $privateKey,
            'client_email' => 'neuron@my-project.iam.gserviceaccount.com',
            'client_id' => '123',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => self::ACCESS_TOKEN,
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($this->tokenRequests));
        HttpClientCache::setHttpClient(new Client(['handler' => $stack]));
    }

    protected function tearDown(): void
    {
        HttpClientCache::setHttpClient();
        @unlink($this->credentialsPath);
    }

    public function test_regional_endpoint_is_called_with_the_oauth_bearer_token(): void
    {
        $provider = new GeminiVertex(
            $this->credentialsPath,
            'europe-west8',
            'my-project',
            'gemini-2.5-pro',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER)),
        );

        $message = $provider->chat(new UserMessage('Hi'))->message();

        $this->assertSame('Hi from Vertex', $message->getContent());
        $this->assertSame([
            'POST https://europe-west8-aiplatform.googleapis.com/v1/projects/my-project/locations/europe-west8/publishers/google/models/gemini-2.5-pro:generateContent',
        ], $this->sentTargets());
        $request = $this->sentRequests[0]['request'];
        $this->assertSame('Bearer '.self::ACCESS_TOKEN, $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('x-goog-api-key'));
    }

    public function test_building_the_provider_fetches_no_token(): void
    {
        $provider = new GeminiVertex($this->credentialsPath, 'us-central1', 'my-project', 'gemini-2.5-pro');

        $this->assertInstanceOf(GeminiVertex::class, $provider);
        $this->assertSame([], $this->tokenRequests);
    }

    public function test_building_the_provider_reads_no_credentials_file(): void
    {
        $credentials = file_get_contents($this->credentialsPath);
        unlink($this->credentialsPath);
        $provider = new GeminiVertex(
            $this->credentialsPath,
            'us-central1',
            'my-project',
            'gemini-2.5-pro',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER)),
        );

        file_put_contents($this->credentialsPath, $credentials);
        $provider->chat(new UserMessage('Hi'));

        $this->assertSame('Bearer '.self::ACCESS_TOKEN, $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_token_is_obtained_with_a_signed_service_account_assertion(): void
    {
        $provider = new GeminiVertex(
            $this->credentialsPath,
            'us-central1',
            'my-project',
            'gemini-2.5-pro',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER)),
        );
        $provider->chat(new UserMessage('Hi'));

        $this->assertCount(1, $this->tokenRequests);
        $tokenRequest = $this->tokenRequests[0]['request'];
        $this->assertSame('POST https://oauth2.googleapis.com/token', $tokenRequest->getMethod().' '.$tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);

        [$header, $claims, $signature] = explode('.', $form['assertion']);
        $this->assertSame('RS256', $this->decodeSegment($header)['alg']);
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $this->base64UrlDecode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $claims = $this->decodeSegment($claims);
        $this->assertSame('neuron@my-project.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        $this->assertSame('https://www.googleapis.com/auth/cloud-platform', $claims['scope']);
    }

    protected function base64UrlDecode(string $segment): string
    {
        return base64_decode(strtr($segment, '-_', '+/'), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeSegment(string $segment): array
    {
        return json_decode($this->base64UrlDecode($segment), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_missing_location_uses_the_global_endpoint_for_streaming(): void
    {
        $provider = new GeminiVertex(
            $this->credentialsPath,
            null,
            'my-project',
            'gemini-2.5-flash',
            httpClient: $this->recordingClient(new Response(200, body: 'data: '.self::ANSWER."\r\n\r\n")),
        );

        iterator_to_array($provider->stream(new UserMessage('Hi')));

        $this->assertSame([
            'POST https://aiplatform.googleapis.com/v1/projects/my-project/locations/global/publishers/google/models/gemini-2.5-flash:streamGenerateContent?alt=sse',
        ], $this->sentTargets());
        $this->assertSame('Bearer '.self::ACCESS_TOKEN, $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
    }

    public function test_a_valid_access_token_is_reused_across_requests(): void
    {
        $provider = new GeminiVertex(
            $this->credentialsPath,
            'us-central1',
            'my-project',
            'gemini-2.5-pro',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER), new Response(200, body: self::ANSWER)),
        );

        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertCount(1, $this->tokenRequests);
        $this->assertSame('Bearer '.self::ACCESS_TOKEN, $this->sentRequests[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_an_access_token_about_to_expire_is_refreshed_before_the_next_request(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.first","expires_in":30,"token_type":"Bearer"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.second","expires_in":3600,"token_type":"Bearer"}'),
        ]));
        HttpClientCache::setHttpClient(new Client(['handler' => $stack]));
        $provider = new GeminiVertex(
            $this->credentialsPath,
            'us-central1',
            'my-project',
            'gemini-2.5-pro',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER), new Response(200, body: self::ANSWER)),
        );

        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertSame('Bearer ya29.first', $this->sentRequests[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('Bearer ya29.second', $this->sentRequests[1]['request']->getHeaderLine('Authorization'));
    }
}
