<?php

declare(strict_types=1);

namespace NeuronAI\Tests\MCP;

use Closure;
use GuzzleHttp\Psr7\Response;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\MCP\SseHttpTransport;
use NeuronAI\MCP\StreamableHttpTransport;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\Tests\Support\RecordsHttpRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Basic-auth credentials given as URL userinfo (the MCP SSE transport keeps
 * them on purpose for its POST endpoint) authenticate the request, but every
 * failure message interpolates the raw URL, so the password reaches logs,
 * WorkflowError events and anything that prints the exception.
 */
class UrlCredentialsTrustBoundarySecurityTest extends TestCase
{
    use RecordsHttpRequests;

    protected const PASSWORD = 's3cr3t-PASSWORD-91';

    /**
     * @return array<string, array{Closure(self): mixed}>
     */
    public static function failures(): array
    {
        return [
            'mcp sse stream that cannot be opened' => [
                static fn (self $test): mixed => (new SseHttpTransport(['url' => 'http://mcp-user:'.self::PASSWORD.'@127.0.0.1:1/sse', 'timeout' => 2]))->connect(),
            ],
            'mcp streamable http request rejected by the server' => [
                static function (self $test): mixed {
                    $transport = new StreamableHttpTransport(['url' => 'https://mcp-user:'.self::PASSWORD.'@mcp.internal/mcp'], $test->recordingClient(new Response(500, body: 'boom')));
                    $transport->connect();
                    $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
                    return null;
                },
            ],
            'provider behind a basic-auth proxy' => [
                static fn (self $test): mixed => (new OpenAILike('https://llm-user:'.self::PASSWORD.'@llm.internal/v1', 'key', 'model', httpClient: $test->recordingClient(new Response(502, body: 'bad gateway'))))->chat(new UserMessage('Hi')),
            ],
            'default curl client network error' => [
                static fn (self $test): mixed => (new CurlHttpClient())->request(new HttpRequest(HttpMethod::GET, 'http://user:'.self::PASSWORD.'@127.0.0.1:1/x', timeout: 2)),
            ],
        ];
    }

    /**
     * @param Closure(self): mixed $failure
     */
    #[DataProvider('failures')]
    public function test_a_password_in_the_url_never_reaches_the_exception_message(Closure $failure): void
    {
        try {
            $failure($this);
            $this->fail('The exchange must fail.');
        } catch (Throwable $exception) {
            $this->assertStringNotContainsString(self::PASSWORD, $exception->getMessage());
        }
    }
}
