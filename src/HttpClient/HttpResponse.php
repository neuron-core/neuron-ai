<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use JsonException;

use function is_array;
use function json_decode;
use function strtolower;

use const JSON_THROW_ON_ERROR;

class HttpResponse
{
    /**
     * @param array<string, string|string[]> $headers
     */
    public function __construct(
        public int $statusCode,
        public string $body,
        public array $headers = [],
    ) {
    }

    /**
     * Decode a JSON object or array body. A body of any other kind is read from $body.
     *
     * @return array<string, mixed>
     * @throws JsonException when the body is not a JSON object or array
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new JsonException('The response body is not a JSON object or array');
        }

        return $decoded;
    }

    /**
     * Check if the response was successful (2xx status).
     */
    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Get a header's first value by case-insensitive name.
     */
    public function header(string $name): ?string
    {
        $name = strtolower($name);

        foreach ($this->headers as $headerName => $value) {
            if (strtolower($headerName) === $name) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }
}
