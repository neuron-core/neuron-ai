<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\HttpException;

use function is_array;
use function trim;

final class GuzzleHttpClient implements HttpClientInterface
{
    protected string $baseUri = '';

    protected Client $client;

    /**
     * @param array<string, mixed> $customHeaders
     */
    public function __construct(
        private readonly array $customHeaders = [],
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly ?HandlerStack $handler = null,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $client = $this->createClient();

        try {
            $options = [
                RequestOptions::HEADERS => [...$this->customHeaders, ...$request->headers],
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
            ];

            if ($request->body !== null) {
                if (is_array($request->body)) {
                    $options[RequestOptions::JSON] = $request->body;
                } else {
                    $options[RequestOptions::BODY] = $request->body;
                }
            }

            $response = $client->request($request->method, $request->uri, $options);

            return new HttpResponse(
                statusCode: $response->getStatusCode(),
                body: $response->getBody()->getContents(),
                headers: $response->getHeaders(),
            );
        } catch (GuzzleException $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $client = $this->createClient();

        try {
            $options = [
                RequestOptions::HEADERS => [...$this->customHeaders, ...$request->headers],
                RequestOptions::TIMEOUT => $this->timeout,
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
                RequestOptions::STREAM => true, // Enable streaming
            ];

            if ($request->body !== null) {
                if (is_array($request->body)) {
                    $options[RequestOptions::JSON] = $request->body;
                } else {
                    $options[RequestOptions::BODY] = $request->body;
                }
            }

            $response = $client->request($request->method, $request->uri, $options);

            return new GuzzleStream($response->getBody());
        } catch (GuzzleException $e) {
            throw HttpException::networkError($request, $e);
        }
    }

    public function withBaseUri(string $baseUri): GuzzleHttpClient
    {
        $new = new self($this->customHeaders, $this->timeout, $this->connectTimeout, $this->handler);
        $new->baseUri = $baseUri;
        return $new;
    }

    public function withHeaders(array $headers): GuzzleHttpClient
    {
        $new = new self(
            [...$this->customHeaders, ...$headers],
            $this->timeout,
            $this->connectTimeout,
            $this->handler
        );
        $new->baseUri = $this->baseUri;
        return $new;
    }

    public function withTimeout(float $timeout): GuzzleHttpClient
    {
        $new = new self($this->customHeaders, $timeout, $this->connectTimeout, $this->handler);
        $new->baseUri = $this->baseUri;
        return $new;
    }

    protected function createClient(): Client
    {
        if (isset($this->client)) {
            return $this->client;
        }

        $config = [];

        if ($this->baseUri !== '') {
            $config['base_uri'] = trim($this->baseUri, '/') . '/';
        }

        if ($this->handler instanceof HandlerStack) {
            $config['handler'] = $this->handler;
        }

        return new Client($config);
    }
}
