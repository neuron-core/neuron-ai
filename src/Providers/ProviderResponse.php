<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Chat\Messages\Message;

class ProviderResponse
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        protected Message $message,
        protected ?string $body = null,
        protected array $headers = [],
        protected array $metadata = [],
    ) {
    }

    public function message(): Message
    {
        return $this->message;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function metadata(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
