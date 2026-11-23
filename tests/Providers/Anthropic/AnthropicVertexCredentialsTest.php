<?php

declare(strict_types=1);

namespace NeuronAI\Tests\Providers\Anthropic;

use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Anthropic\AnthropicVertex;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

/**
 * Exercises the real credentials: the OAuth token exchange goes through the
 * provider's HTTP client, whose first queued response answers it, so no
 * network is involved.
 */
class AnthropicVertexCredentialsTest extends TestCase
{
    use RecordsHttpRequests;

    protected static string $privateKey = '';

    protected const ANSWER = '{"content":[{"type":"text","text":"Hi"}]}';

    protected const TOKEN_URI = 'https://oauth2.googleapis.com/token';

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
            'token_uri' => self::TOKEN_URI,
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
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
            httpClient: $this->recordingClient($this->tokenResponse('ya29.vertex-token'), new Response(200, body: self::ANSWER)),
        );

        $provider->chat(new UserMessage('Hi'));

        $request = $this->sentRequests[1]['request'];
        $this->assertSame(['POST '.self::TOKEN_URI, "POST {$endpoint}"], $this->sentTargets());
        $this->assertSame(10.0, $this->sentRequests[0]['options']['timeout']);
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
            httpClient: $this->recordingClient($this->tokenResponse('ya29.vertex-token'), new Response(200, body: self::ANSWER)),
        );

        $provider->chat(new UserMessage('Hi'));

        $body = json_decode((string) $this->sentRequests[1]['request']->getBody(), true);
        $this->assertSame(0.1, $body['temperature']);
    }

    public function test_building_the_provider_fetches_no_token(): void
    {
        $this->provider();

        $this->assertSame([], $this->sentRequests);
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
        $provider = $this->provider($this->tokenResponse('ya29.vertex-token'), new Response(200, body: self::ANSWER), new Response(200, body: 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n" . 'data: {"type":"message_stop"}' . "\n\n"));
        $provider->chat(new UserMessage('Hi'));
        iterator_to_array($provider->stream(new UserMessage('Hi again')));

        $this->assertSame(['Bearer ya29.vertex-token', 'Bearer ya29.vertex-token'], $this->sentAuthorizations());
        $this->assertCount(3, $this->sentRequests, 'one token request and two API calls');
    }

    public function test_an_access_token_about_to_expire_is_refreshed_before_the_next_request(): void
    {
        $provider = $this->provider(
            $this->tokenResponse('ya29.first', expiresIn: 30),
            new Response(200, body: self::ANSWER),
            $this->tokenResponse('ya29.second'),
            new Response(200, body: self::ANSWER),
        );
        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertSame(['Bearer ya29.first', 'Bearer ya29.second'], $this->sentAuthorizations());
    }

    protected function provider(Response ...$responses): AnthropicVertex
    {
        return new AnthropicVertex(
            pathJsonCredentials: $this->credentialsFile,
            location: 'us-east5',
            projectId: 'test-project',
            model: 'claude-test',
            httpClient: $this->recordingClient(...($responses !== [] ? $responses : [$this->tokenResponse('ya29.vertex-token'), new Response(200, body: self::ANSWER)])),
        );
    }

    protected function tokenResponse(string $accessToken, int $expiresIn = 3600): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<string> The Authorization header of each API call, token requests left out.
     */
    protected function sentAuthorizations(): array
    {
        $apiCalls = array_filter(
            $this->sentRequests,
            static fn (array $entry): bool => (string) $entry['request']->getUri() !== self::TOKEN_URI,
        );

        return array_values(array_map(
            static fn (array $entry): string => $entry['request']->getHeaderLine('Authorization'),
            $apiCalls,
        ));
    }
}
