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
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_put_contents;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;

class AnthropicVertexTokenRefreshTest extends TestCase
{
    use RecordsHttpRequests;

    protected const ANSWER = '{"content":[{"type":"text","text":"Hi"}]}';

    protected static string $privateKey = '';

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

        $this->tokenEndpoint = new MockHandler();
        HttpClientCache::setHttpClient(new Client(['handler' => HandlerStack::create($this->tokenEndpoint)]));
    }

    protected function tearDown(): void
    {
        HttpClientCache::setHttpClient(null);
        @unlink($this->credentialsFile);
    }

    protected function issueToken(string $accessToken, int $expiresIn): void
    {
        $this->tokenEndpoint->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => $accessToken,
            'expires_in' => $expiresIn,
            'token_type' => 'Bearer',
        ], JSON_THROW_ON_ERROR)));
    }

    protected function provider(): AnthropicVertex
    {
        return new AnthropicVertex(
            pathJsonCredentials: $this->credentialsFile,
            location: 'us-east5',
            projectId: 'test-project',
            model: 'claude-test',
            httpClient: $this->recordingClient(new Response(200, body: self::ANSWER), new Response(200, body: self::ANSWER)),
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

    public function test_an_expired_access_token_is_refreshed_before_the_next_request(): void
    {
        $this->issueToken('ya29.first', 1);
        $this->issueToken('ya29.second', 3600);

        $provider = $this->provider();
        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertSame(['Bearer ya29.first', 'Bearer ya29.second'], $this->sentAuthorizations());
    }

    public function test_a_valid_access_token_is_reused_across_requests(): void
    {
        $this->issueToken('ya29.long-lived', 3600);

        $provider = $this->provider();
        $provider->chat(new UserMessage('Hi'));
        $provider->chat(new UserMessage('Hi again'));

        $this->assertSame(['Bearer ya29.long-lived', 'Bearer ya29.long-lived'], $this->sentAuthorizations());
        $this->assertSame(0, $this->tokenEndpoint->count());
    }
}
