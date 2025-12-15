<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use NeuronAI\Exceptions\HttpException;
use Throwable;

use function is_array;
use function json_encode;
use function trim;

/**
 * Amp HTTP client adapter for async workflows.
 *
 * This implementation leverages Amp's Fiber-based async runtime,
 * allowing true non-blocking I/O when used within AmpWorkflowExecutor.
 *
 * Example usage:
 * ```php
 * // Provider configures it automatically with API keys, base URI, etc.
 * $httpClient = new AmpHttpClient();
 *
 * $provider = new Anthropic(
 *     key: 'sk-...',
 *     model: 'claude-3-5-sonnet-20241022',
 *     httpClient: $httpClient  // Provider configures headers/URI internally
 * );
 *
 * $agent = Agent::make($provider);
 * $executor = new AmpWorkflowExecutor();
 * $future = $executor->execute($agent->chat('Hello'));
 * $result = $future->await();
 * ```
 */
final class AmpHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    /**
     * @param array<string, string> $customHeaders
     * @param HttpClient|null $client Internal Amp HTTP client instance
     */
    public function __construct(
        private readonly array       $customHeaders = [],
        private readonly float       $timeout = 30.0,
        private readonly ?HttpClient $client = null,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        try {
            $client = $this->client ?? HttpClientBuilder::buildDefault();

            $uri = $this->baseUri !== '' && $this->baseUri !== '0'
                ? trim($this->baseUri, '/') . '/' . trim($request->uri, '/')
                : $request->uri;

            $ampRequest = new Request($uri, $request->method);

            // Set headers
            foreach ([...$this->customHeaders, ...$request->headers] as $name => $value) {
                $ampRequest->setHeader((string)$name, $value);
            }

            // Set body if present
            if ($request->body !== null) {
                if (is_array($request->body)) {
                    $ampRequest->setHeader('Content-Type', 'application/json');
                    $ampRequest->setBody(json_encode($request->body));
                } else {
                    $ampRequest->setBody($request->body);
                }
            }

            // Execute request (suspends Fiber in async context)
            $response = $client->request($ampRequest);

            return new HttpResponse(
                statusCode: $response->getStatus(),
                body: $response->getBody()->buffer(),
                headers: $response->getHeaders(),
            );
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        try {
            $client = $this->client ?? HttpClientBuilder::buildDefault();

            $uri = $this->baseUri !== '' && $this->baseUri !== '0'
                ? trim($this->baseUri, '/') . '/' . trim($request->uri, '/')
                : $request->uri;

            $ampRequest = new Request($uri, $request->method);

            // Set headers
            foreach ([...$this->customHeaders, ...$request->headers] as $name => $value) {
                $ampRequest->setHeader((string)$name, $value);
            }

            // Set body if present
            if ($request->body !== null) {
                if (is_array($request->body)) {
                    $ampRequest->setHeader('Content-Type', 'application/json');
                    $ampRequest->setBody(json_encode($request->body));
                } else {
                    $ampRequest->setBody($request->body);
                }
            }

            // Execute request and get streaming body
            $response = $client->request($ampRequest);

            return new AmpStream($response->getBody());
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function withBaseUri(string $baseUri): static
    {
        $new = new self($this->customHeaders, $this->timeout, $this->client);
        $new->baseUri = $baseUri;
        return $new;
    }

    public function withHeaders(array $headers): static
    {
        $new = new self([...$this->customHeaders, ...$headers], $this->timeout, $this->client);
        $new->baseUri = $this->baseUri;
        return $new;
    }

    public function withTimeout(float $timeout): static
    {
        $new = new self($this->customHeaders, $timeout, $this->client);
        $new->baseUri = $this->baseUri;
        return $new;
    }
}
