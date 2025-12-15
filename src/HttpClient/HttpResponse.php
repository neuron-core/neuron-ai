<?php

declare(strict_types=1);

namespace NeuronAI\HttpClient;

use function json_decode;

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
}
