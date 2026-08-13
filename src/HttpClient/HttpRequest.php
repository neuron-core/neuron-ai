<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use InvalidArgumentException;

use function is_array;
use function is_resource;
use function strpbrk;

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
        if (strpbrk($this->uri, "\r\n") !== false) {
            throw new InvalidArgumentException('URI must not contain line breaks');
        }
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
     * Whether the body must be sent as multipart form data: an array body
     * containing resources or `['contents' => ...]` file parts. Anything
     * else is JSON-encoded (array) or sent raw (string).
     */
    public function isMultipart(): bool
    {
        if (!is_array($this->body)) {
            return false;
        }

        foreach ($this->body as $value) {
            if (is_resource($value)) {
                return true;
            }

            if (is_array($value) && isset($value['contents'])) {
                return true;
            }
        }

        return false;
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
