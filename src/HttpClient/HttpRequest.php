<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    public function __construct(
        public HttpMethod $method,
        public string $uri,
        public array $headers = [],
        public array|string|null $body = null,
    ) {
    }

    /**
     * Create a GET request.
     *
     * @param array<string, string> $headers
     */
    public static function get(string $uri, array $headers = []): self
    {
        return new self(HttpMethod::GET, $uri, $headers);
    }

    /**
     * Create a POST request with JSON body.
     *
     * @param array<string, string> $headers
     */
    public static function post(string $uri, array $body = [], array $headers = []): self
    {
        return new self(HttpMethod::POST, $uri, $headers, $body);
    }

    /**
     * Create a PUT request with JSON body.
     *
     * @param array<string, string> $headers
     */
    public static function put(string $uri, array $body = [], array $headers = []): self
    {
        return new self(HttpMethod::PUT, $uri, $headers, $body);
    }

    /**
     * Create a PATCH request with JSON body.
     *
     * @param array<string, string> $headers
     */
    public static function patch(string $uri, array $body = [], array $headers = []): self
    {
        return new self(HttpMethod::PATCH, $uri, $headers, $body);
    }

    /**
     * Create a POST request with JSON body.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function delete(string $uri, array $body = [], array $headers = []): self
    {
        return new self(HttpMethod::DELETE, $uri, $headers, $body);
    }

    /**
     * Create a new request with additional headers.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self(
            $this->method,
            $this->uri,
            [...$this->headers, ...$headers],
            $this->body
        );
    }
}
