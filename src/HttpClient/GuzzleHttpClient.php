<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\RequestOptions;
use NeuronAI\Exceptions\HttpException;

use function is_array;
use function is_resource;
use function trim;

class GuzzleHttpClient implements HttpClientInterface
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

            $response = $this->runRequest($request, $options, $client);

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

            $response = $this->runRequest($request, $options, $client);

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

    /**
     * Check if the body array contains multipart data (resources or nested arrays).
     *
     * @param array<string, mixed> $body
     */
    protected function isMultipartData(array $body): bool
    {
        foreach ($body as $value) {
            if (is_resource($value)) {
                return true;
            }

            if (is_array($value) && isset($value['contents']) && is_resource($value['contents'])) {
                return true;
            }
        }

        return false;
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
     * @param HttpRequest $request
     * @throws GuzzleException
     */
    public function runRequest(HttpRequest $request, array $options, Client $client): \Psr\Http\Message\ResponseInterface
    {
        if ($request->body !== null) {
            if (is_array($request->body)) {
                // Check if the body contains resources (multipart data)
                if ($this->isMultipartData($request->body)) {
                    $options[RequestOptions::MULTIPART] = $this->buildMultipartData($request->body);
                } else {
                    $options[RequestOptions::JSON] = $request->body;
                }
            } else {
                $options[RequestOptions::BODY] = $request->body;
            }
        }

        return $client->request($request->method->value, $request->uri, $options);
    }
}
