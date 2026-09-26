<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use Google\Auth\HttpHandler\HttpClientCache;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\AnthropicVertex;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Exercises the real constructor: the OAuth token exchange is answered by a
 * mocked Google token endpoint, so no network is involved.
 */
class AnthropicVertexCredentialsTest extends TestCase
{
    use RecordsHttpRequests;

    protected static string $privateKey = '';

    protected string $credentialsFile;

    public static function setUpBeforeClass(): void
    {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), self::$privateKey);
    }

    protected function setUp(): void
    {
        $this->credentialsFile = tempnam(sys_get_temp_dir(), 'neuron_vertex_');
        file_put_contents($this->credentialsFile, json_encode([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'private_key_id' => 'key-id',
            'private_key' => self::$privateKey,
            'client_email' => 'neuron@test-project.iam.gserviceaccount.com',
            'client_id' => '1',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ], JSON_THROW_ON_ERROR));

        HttpClientCache::setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.vertex-token","expires_in":3600,"token_type":"Bearer"}'),
        ]))]));
    }

    protected function tearDown(): void
    {
        HttpClientCache::setHttpClient(null);
        @unlink($this->credentialsFile);
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function locations(): array
    {
        return [
            'regional' => ['us-east5', 'https://us-east5-aiplatform.googleapis.com/v1/projects/test-project/locations/us-east5/publishers/anthropic/models/claude-test:rawPredict'],
            'global' => [null, 'https://aiplatform.googleapis.com/v1/projects/test-project/locations/global/publishers/anthropic/models/claude-test:rawPredict'],
        ];
    }

    #[DataProvider('locations')]
    public function test_requests_target_the_location_endpoint_with_the_fetched_bearer_token(?string $location, string $endpoint): void
    {
        $provider = new AnthropicVertex(
            pathJsonCredentials: $this->credentialsFile,
            location: $location,
            projectId: 'test-project',
            model: 'claude-test',
            httpClient: $this->recordingClient(new Response(200, body: '{"content":[{"type":"text","text":"Hi"}]}')),
        );

        $provider->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[0]['request'];
        $this->assertSame(["POST {$endpoint}"], $this->sentTargets());
        $this->assertSame('Bearer ya29.vertex-token', $request->getHeaderLine('Authorization'));
        $this->assertFalse($request->hasHeader('x-api-key'));
        $this->assertFalse($request->hasHeader('anthropic-version'));
    }
}
