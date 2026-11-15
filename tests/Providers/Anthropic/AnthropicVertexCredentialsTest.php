<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use Google\Auth\HttpHandler\HttpClientCache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Providers\Anthropic\AnthropicVertex;
use NeuronAI\Tests\Providers\Stub\QueuedHttpClient;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function parse_str;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Exercises the real credentials: the OAuth token exchange is answered by a
 * mocked Google token endpoint, so no network is involved.
 */
class AnthropicVertexCredentialsTest extends TestCase
{
    use RecordsHttpRequests;

    protected static string $privateKey = '';

    protected const ANSWER = '{"content":[{"type":"text","text":"Hi"}]}';

    protected string $credentialsFile;

    protected MockHandler $tokenEndpoint;

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

        $this->tokenEndpoint = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.vertex-token","expires_in":3600,"token_type":"Bearer"}'),
        ]);
        HttpClientCache::setHttpClient(new Client(['handler' => HandlerStack::create($this->tokenEndpoint)]));
    }

    protected function tearDown(): void
    {
        HttpClientCache::setHttpClient();
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

    public function test_parameters_are_sent_in_the_request_body(): void
    {
        $provider = new AnthropicVertex(
            pathJsonCredentials: $this->credentialsFile,
            location: 'us-east5',
            projectId: 'test-project',
            model: 'claude-test',
            parameters: ['temperature' => 0.1],
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER)),
        );

        $provider->chat(new UserMessage('Hi'));

        $body = json_decode((string) $this->sentRequests[0]['request']->getBody(), true);
        $this->assertSame(0.1, $body['temperature']);
    }

    public function test_building_the_provider_fetches_no_token(): void
    {
        $this->provider();

        $this->assertSame(1, $this->tokenEndpoint->count());
    }

    public function test_building_the_provider_reads_no_credentials_file(): void
    {
        $credentials = file_get_contents($this->credentialsFile);
        unlink($this->credentialsFile);
        $provider = $this->provider();

        file_put_contents($this->credentialsFile, $credentials);
        $provider->chat(new UserMessage('Hi'));

        $this->assertSame(['Bearer ya29.vertex-token'], $this->sentAuthorizations());
    }

    public function test_a_valid_access_token_is_reused_across_requests(): void
    {
        $provider = $this->provider(new Response(200, body: self::ANSWER), new Response(200, body: 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n" . 'data: {"type":"message_stop"}' . "\n\n"));
        $provider->chat(new UserMessage('Hi'));
        iterator_to_array($provider->stream(new UserMessage('Hi again')));

        $this->assertSame(['Bearer ya29.vertex-token', 'Bearer ya29.vertex-token'], $this->sentAuthorizations());
        $this->assertSame(0, $this->tokenEndpoint->count());
    }

    public function test_an_access_token_about_to_expire_is_refreshed_before_the_next_request(): void
    {
        $this->tokenEndpoint->reset();
        $this->tokenEndpoint->append(
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.first","expires_in":30,"token_type":"Bearer"}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"ya29.second","expires_in":3600,"token_type":"Bearer"}'),
        );

        $provider = $this->provider();
        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertSame(['Bearer ya29.first', 'Bearer ya29.second'], $this->sentAuthorizations());
    }

    public function test_an_injected_auth_client_sends_the_token_request(): void
    {
        $authHttpClient = $this->authHttpClient(3600);

        $this->providerWithAuthClient($authHttpClient)->chat(new UserMessage('Hi'));

        $this->assertCount(1, $authHttpClient->requests);
        $tokenRequest = $authHttpClient->requests[0];
        $this->assertSame('POST https://oauth2.googleapis.com/token', "{$tokenRequest->method->value} {$tokenRequest->uri}");
        $this->assertSame('application/x-www-form-urlencoded', $tokenRequest->headers['Content-Type']);
        parse_str((string) $tokenRequest->body, $form);
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type']);
        $this->assertSame(['Bearer ya29.auth-client-token-1'], $this->sentAuthorizations());
        $this->assertSame(1, $this->tokenEndpoint->count(), 'google/auth default client must stay unused');
    }

    public function test_the_api_client_never_sends_the_token_request(): void
    {
        $this->providerWithAuthClient($this->authHttpClient(3600))->chat(new UserMessage('Hi'));

        $this->assertSame(['POST https://us-east5-aiplatform.googleapis.com/v1/projects/test-project/locations/us-east5/publishers/anthropic/models/claude-test:rawPredict'], $this->sentTargets());
    }

    public function test_the_auth_client_reuses_a_valid_token(): void
    {
        $authHttpClient = $this->authHttpClient(3600, 3600);

        $provider = $this->providerWithAuthClient($authHttpClient);
        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertCount(1, $authHttpClient->requests);
        $this->assertSame(['Bearer ya29.auth-client-token-1', 'Bearer ya29.auth-client-token-1'], $this->sentAuthorizations());
    }

    public function test_the_auth_client_renews_a_token_about_to_expire(): void
    {
        $authHttpClient = $this->authHttpClient(30, 3600);

        $provider = $this->providerWithAuthClient($authHttpClient);
        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertCount(2, $authHttpClient->requests);
        $this->assertSame(['Bearer ya29.auth-client-token-1', 'Bearer ya29.auth-client-token-2'], $this->sentAuthorizations());
    }

    public function test_a_token_error_from_the_auth_client_surfaces_as_on_the_default_path(): void
    {
        $error = '{"error":"invalid_grant","error_description":"Invalid JWT Signature."}';
        $this->tokenEndpoint->reset();
        $this->tokenEndpoint->append(new Response(400, ['Content-Type' => 'application/json'], $error));
        $authHttpClient = new QueuedHttpClient(new HttpResponse(400, $error, ['Content-Type' => 'application/json']));

        $defaultPathError = $this->chatError($this->provider());
        $authClientError = $this->chatError($this->providerWithAuthClient($authHttpClient));

        $this->assertInstanceOf(ClientException::class, $defaultPathError);
        $this->assertInstanceOf($defaultPathError::class, $authClientError);
        $this->assertSame(400, $authClientError->getResponse()->getStatusCode());
        $this->assertSame($error, (string) $authClientError->getResponse()->getBody());
    }

    protected function chatError(AnthropicVertex $provider): ClientException
    {
        try {
            $provider->chat(new UserMessage('Hi'));
        } catch (ClientException $exception) {
            return $exception;
        }

        $this->fail('The token error was not raised');
    }

    /**
     * An auth client answering one token exchange per given lifetime, with
     * tokens numbered in order: ya29.auth-client-token-1, -2, ...
     */
    protected function authHttpClient(int ...$expiresIn): QueuedHttpClient
    {
        $responses = [];
        foreach ($expiresIn as $index => $seconds) {
            $responses[] = new HttpResponse(200, sprintf('{"access_token":"ya29.auth-client-token-%d","expires_in":%d,"token_type":"Bearer"}', $index + 1, $seconds), ['Content-Type' => 'application/json']);
        }

        return new QueuedHttpClient(...$responses);
    }

    protected function providerWithAuthClient(HttpClientInterface $authHttpClient): AnthropicVertex
    {
        return new AnthropicVertex(
            pathJsonCredentials: $this->credentialsFile,
            location: 'us-east5',
            projectId: 'test-project',
            model: 'claude-test',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER), new Response(200, body: self::ANSWER)),
            authHttpClient: $authHttpClient,
        );
    }

    protected function provider(Response ...$responses): AnthropicVertex
    {
        return new AnthropicVertex(
            pathJsonCredentials: $this->credentialsFile,
            location: 'us-east5',
            projectId: 'test-project',
            model: 'claude-test',
            httpClient: $this->recordingClient(...($responses !== [] ? $responses : [new Response(200, body: self::ANSWER), new Response(200, body: self::ANSWER)])),
        );
    }

    /**
     * @return list<string>
     */
    protected function sentAuthorizations(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getHeaderLine('Authorization'),
            $this->sentRequests,
        );
    }
}
