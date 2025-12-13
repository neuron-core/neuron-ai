<?php

declare(strict_types=1);

namespace NeuronAI\Providers\HttpClient;

class HttpRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|string|null $body
     */
    public function __construct(
        public string $method,
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
        return new self('GET', $uri, $headers);
    }

    /**
     * Create a POST request with JSON body.
     *
     * @param array<string, mixed> $json
     * @param array<string, string> $headers
     */
    public static function post(string $uri, array $json, array $headers = []): self
    {
        return new self('POST', $uri, $headers, $json);
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
