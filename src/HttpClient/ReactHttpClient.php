<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use NeuronAI\Exceptions\HttpException;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use Throwable;

use function Clue\React\Block\await;
use function is_array;
use function json_encode;
use function trim;

class ReactHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    protected ?Browser $client = null;

    /**
     * @param array<string, string> $customHeaders
     */
    public function __construct(
        protected readonly array $customHeaders = [],
        protected readonly float $timeout = 30.0,
        protected readonly ?LoopInterface $loop = null,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $loop = $this->loop ?? Loop::get();

        try {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = await($this->executeAsync($request), $loop, $this->timeout);

            return new HttpResponse(
                statusCode: $response->getStatusCode(),
                body: (string)$response->getBody(),
                headers: $response->getHeaders(),
            );
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $loop = $this->loop ?? Loop::get();

        try {
            /** @var \Psr\Http\Message\ResponseInterface $response */
            $response = await($this->executeAsync($request, true), $loop, $this->timeout);

            return new ReactStream($response->getBody(), $loop);
        } catch (Throwable $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function withBaseUri(string $baseUri): ReactHttpClient
    {
        $new = new self($this->customHeaders, $this->timeout, $this->loop);
        $new->baseUri = $baseUri;
        return $new;
    }

    public function withHeaders(array $headers): ReactHttpClient
    {
        $new = new self([...$this->customHeaders, ...$headers], $this->timeout, $this->loop);
        $new->baseUri = $this->baseUri;
        return $new;
    }

    public function withTimeout(float $timeout): ReactHttpClient
    {
        $new = new self($this->customHeaders, $timeout, $this->loop);
        $new->baseUri = $this->baseUri;
        return $new;
    }

    protected function executeAsync(HttpRequest $request, bool $streaming = false): PromiseInterface
    {
        $client = $this->getClient();

        $uri = $this->baseUri !== '' && $this->baseUri !== '0'
            ? trim($this->baseUri, '/') . '/' . trim($request->uri, '/')
            : $request->uri;

        // Merge headers
        $headers = [...$this->customHeaders, ...$request->headers];

        // Handle request body
        $body = null;
        if ($request->body !== null) {
            if (is_array($request->body)) {
                $headers['Content-Type'] = 'application/json';
                $body = json_encode($request->body);
            } else {
                $body = $request->body;
            }
        }

        // Make request based on method
        return match ($request->method->value) {
            'GET' => $client->get($uri, $headers),
            'POST' => $client->post($uri, $headers, $body ?? ''),
            'PUT' => $client->put($uri, $headers, $body ?? ''),
            'DELETE' => $client->delete($uri, $headers, $body ?? ''),
            'PATCH' => $client->patch($uri, $headers, $body ?? ''),
            'HEAD' => $client->head($uri, $headers),
            default => $client->request($request->method->value, $uri, $headers, $body ?? ''),
        };
    }

    protected function getClient(): Browser
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $loop = $this->loop ?? Loop::get();

        return new Browser($loop);
    }
}
