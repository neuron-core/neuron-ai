<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

class HttpClientOptions
{
    /**
     * @param array<string, string|int|float>|null $headers
     */
    public function __construct(
        public readonly ?int $timeout = null,
        public readonly ?int $connectTimeout = null,
        public readonly ?array $headers = null,
    ) {
    }
}
