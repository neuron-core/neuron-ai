<?php

declare(strict_types=1);

namespace NeuronAI\Tests\HttpClient;

use Closure;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use NeuronAI\HttpClient\Amp\AmpHttpClient;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\Guzzle\GuzzleHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpMethod;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Spatie\Fork\Fork;
use Throwable;

use function class_exists;
use function function_exists;
use function json_decode;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

class ForkedProcessConnectionsTest extends TestCase
{
    use BootsFixtureServer;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !class_exists(Fork::class)) {
            $this->markTestSkipped('Forking requires the pcntl extension and spatie/fork.');
        }
    }

    /**
     * Guzzle streams through PHP's stream wrapper, which opens a connection per request.
     *
     * @return iterable<string, array{Closure(): HttpClientInterface, bool}>
     */
    public static function clients(): iterable
    {
        yield 'curl request' => [static fn (): HttpClientInterface => new CurlHttpClient(), false];
        yield 'curl stream' => [static fn (): HttpClientInterface => new CurlHttpClient(), true];
        yield 'guzzle request' => [static fn (): HttpClientInterface => new GuzzleHttpClient(), false];
        yield 'amp request' => [static fn (): HttpClientInterface => new AmpHttpClient(), false];
        yield 'amp stream' => [static fn (): HttpClientInterface => new AmpHttpClient(), true];
    }

    #[DataProvider('clients')]
    public function test_forked_children_open_their_own_connections(Closure $makeClient, bool $stream): void
    {
        $client = $makeClient();
        $parent = $this->ask($client, 'parent', $stream);
        $this->assertSame('parent', $parent['value']);

        [$first, $second] = Fork::new()->run(
            fn (): array => $this->ask($client, 'first', $stream),
            fn (): array => $this->ask($client, 'second', $stream),
        );

        $this->assertSame('first', $first['value']);
        $this->assertSame('second', $second['value']);
        $this->assertNotContains($parent['connection'], [$first['connection'], $second['connection']]);
        $this->assertNotSame($first['connection'], $second['connection']);
        // The children left the parent's connection intact.
        $this->assertSame($parent['connection'], $this->ask($client, 'parent', $stream)['connection']);
    }

    public function test_guzzle_keeps_an_application_handler_in_forked_children(): void
    {
        // Its connections, like its lifetime, belong to the application.
        $client = new GuzzleHttpClient(handler: HandlerStack::create(new MockHandler([
            new Response(200, [], 'first'),
            new Response(200, [], 'second'),
        ])));
        $client->request(new HttpRequest(HttpMethod::GET, 'http://neuron.test/'));

        [$body] = Fork::new()->run(function () use ($client): string {
            try {
                return $client->request(new HttpRequest(HttpMethod::GET, 'http://neuron.test/'))->body;
            } catch (Throwable $e) {
                return $e::class . ': ' . $e->getMessage();
            }
        });

        $this->assertSame('second', $body);
    }

    protected static function serverCommand(int $port): array
    {
        return [PHP_BINARY, __DIR__ . '/fixtures/keep_alive_server.php', (string) $port];
    }

    /**
     * @return array{connection: ?string, value: string}
     */
    protected function ask(HttpClientInterface $client, string $value, bool $stream): array
    {
        $request = new HttpRequest(HttpMethod::GET, static::$baseUri . "/?value={$value}", timeout: 5.0);

        try {
            $body = $stream ? $this->drain($client->stream($request)) : $client->request($request)->body;

            return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            // Reported through the assertions: a child that threw would only return nothing.
            return ['connection' => null, 'value' => $e::class . ': ' . $e->getMessage()];
        }
    }

    protected function drain(StreamInterface $stream): string
    {
        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(8192);
        }
        $stream->close();

        return $body;
    }
}
