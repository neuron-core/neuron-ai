<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use function is_array;
use function json_decode;
use function strtolower;

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
     * Decode JSON body.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return json_decode($this->body, true) ?? [];
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
