<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\MergesHttpHeaders;
use NeuronAI\HttpClient\ResolvesHttpRequest;
use NeuronAI\HttpClient\StreamInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

use function getmypid;
use function is_array;
use function is_resource;
use function method_exists;

class GuzzleHttpClient implements HttpClientInterface
{
    use ResolvesHttpRequest;
    use MergesHttpHeaders;

    protected string $baseUri = '';

    protected ?Client $client = null;

    /**
     * The process that created the client, and with it its default handler's connections.
     */
    protected ?int $clientProcessId = null;

    /**
     * Clients inherited from a parent process, never used again (see leaveInheritedConnections()).
     *
     * @var array<int, Client>
     */
    protected array $inheritedClients = [];

    /**
     * @param array<string, mixed> $customHeaders
     */
    public function __construct(
        protected array $customHeaders = [],
        protected float $timeout = 120.0,
        protected float $connectTimeout = 10.0,
        protected ?HandlerStack $handler = null,
        protected array $options = [],
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $client = $this->createClient();

        try {
            $options = [
                ...$this->options,
                RequestOptions::HEADERS => $this->mergeRequestHeaders($this->customHeaders, $request->headers),
                RequestOptions::TIMEOUT => $request->timeout ?? $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            ];

            $response = $this->runRequest($request, $options, $client);

            return new HttpResponse(
                statusCode: $response->getStatusCode(),
                body: (string) $response->getBody(),
                headers: $response->getHeaders(),
            );
        } catch (GuzzleException $e) {
            $this->handleException($request, $e);
        }
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $client = $this->createClient();

        try {
            $options = [
                RequestOptions::HEADERS => $this->mergeRequestHeaders($this->customHeaders, $request->headers),
                RequestOptions::TIMEOUT => $request->timeout ?? $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
                RequestOptions::STREAM => true, // Enable streaming
            ];

            $response = $this->runRequest($request, $options, $client);

            return new GuzzleStream($response->getBody());
        } catch (GuzzleException $e) {
            $this->handleException($request, $e);
        }
    }

    public function withBaseUri(string $baseUri): static
    {
        $this->baseUri = $baseUri;
        return $this;
    }

    public function withHeaders(array $headers): static
    {
        $this->customHeaders = $this->mergeRequestHeaders($this->customHeaders, $headers);
        return $this;
    }

    public function withTimeout(float $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    protected function createClient(): Client
    {
        $this->leaveInheritedConnections();

        if ($this->client instanceof Client) {
            return $this->client;
        }

        // Guzzle adds client-level headers only when the request lacks them.
        $config = [
            RequestOptions::HEADERS => ['User-Agent' => HttpClientInterface::USER_AGENT],
        ];

        if ($this->handler instanceof HandlerStack) {
            $config['handler'] = $this->handler;
        }

        $this->client = new Client($config);

        return $this->client;
    }

    /**
     * As in CurlHttpClient, a forked child opens its own connections instead of writing to
     * its parent's, and keeps the inherited client referenced so they stay open. An
     * application handler is kept: its connections, like its lifetime, are the application's.
     */
    protected function leaveInheritedConnections(): void
    {
        $processId = (int) getmypid();

        if (!$this->handler instanceof HandlerStack && $this->client instanceof Client && $this->clientProcessId !== $processId) {
            $this->inheritedClients[] = $this->client;
            $this->client = null;
        }

        $this->clientProcessId = $processId;
    }

    /**
     * Build multipart data array in Guzzle format.
     *
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    protected function buildMultipartData(array $data): array
    {
        $multipartData = [];

        foreach ($data as $name => $value) {
            // If it's already a properly formatted array with 'name' key, use it as-is
            if (is_array($value) && isset($value['name'])) {
                $multipartData[] = $value;
                continue;
            }

            // Otherwise, convert to Guzzle format
            $part = ['name' => $name];

            if (is_resource($value)) {
                $part['contents'] = $value;
            } elseif (is_array($value) && isset($value['contents'])) {
                $part['contents'] = $value['contents'];
                if (isset($value['filename'])) {
                    $part['filename'] = $value['filename'];
                }
                if (isset($value['headers'])) {
                    $part['headers'] = $value['headers'];
                }
            } else {
                $part['contents'] = (string) $value;
            }

            $multipartData[] = $part;
        }

        return $multipartData;
    }

    /**
     * @param array<string, mixed> $options
     * @throws GuzzleException
     */
    public function runRequest(HttpRequest $request, array $options, Client $client): ResponseInterface
    {
        if ($request->body !== null) {
            if (is_array($request->body)) {
                // Check if the body contains resources (multipart data)
                if ($request->isMultipart()) {
                    $options[RequestOptions::MULTIPART] = $this->buildMultipartData($request->body);
                } else {
                    $options[RequestOptions::JSON] = $request->body;
                }
            } else {
                $options[RequestOptions::BODY] = $request->body;
            }
        }

        $uri = $this->resolveRequestUri($request->uri, $this->baseUri);

        // Guzzle drops only Authorization and Cookie when a redirect leaves the origin, while
        // providers also authenticate through custom headers such as x-api-key
        $options[RequestOptions::ALLOW_REDIRECTS] ??= [
            'on_redirect' => function (RequestInterface $redirected, ResponseInterface $response, UriInterface $target) use ($request, $uri): void {
                if (!$this->isSameOrigin($uri, (string) $target)) {
                    throw HttpException::networkError($request, "refused a redirect to another origin: {$target}");
                }
            },
        ];

        return $client->request($request->method->value, $uri, $options);
    }

    /**
     * @throws HttpException
     */
    protected function handleException(HttpRequest $request, GuzzleException $e): never
    {
        if ($e instanceof ResponseException || ($e instanceof RequestException && method_exists($e, 'hasResponse') && $e->hasResponse())) {
            $psrResponse = $e->getResponse();
            $response = new HttpResponse(
                statusCode: $psrResponse->getStatusCode(),
                body: (string) $psrResponse->getBody(),
                headers: $psrResponse->getHeaders(),
            );

            throw HttpException::statusError($request, $response, $e);
        }

        throw HttpException::networkError($request, $e->getMessage(), $e);
    }
}
