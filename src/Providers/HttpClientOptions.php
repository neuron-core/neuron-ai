<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

class HttpClientOptions
{
    /**
     * @param array<string, string|int|float>|null $headers
     */
    public function __construct(
        public readonly ?float $timeout = null,
        public readonly ?float $connectTimeout = null,
        public readonly ?array $headers = null,
    ) {
    }
}
