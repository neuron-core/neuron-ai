<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

trait HasMetadata
{
    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    /**
     * @param string|array<int, mixed>|null $value
     */
    public function addMetadata(string $key, string|array|null $value): self
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public function getMetadata(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }
}
