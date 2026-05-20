<?php

declare(strict_types=1);

namespace NeuronAI\Router\Rules;

class MethodRule implements RoutingRuleInterface
{
    protected ?string $chat = null;

    protected ?string $stream = null;

    protected ?string $structured = null;

    public function __construct(protected string $default)
    {
    }

    public function chat(string $provider): self
    {
        $this->chat = $provider;
        return $this;
    }

    public function stream(string $provider): self
    {
        $this->stream = $provider;
        return $this;
    }

    public function structured(string $provider): self
    {
        $this->structured = $provider;
        return $this;
    }

    public function resolveProvider(string $method, array $messages, array $tools): string
    {
        return match ($method) {
            'chat' => $this->chat ?? $this->default,
            'stream' => $this->stream ?? $this->default,
            'structured' => $this->structured ?? $this->default,
            default => $this->default,
        };
    }
}
