<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages;

trait HasMetadata
{
    /**
     * @var array<string, mixed>
     */
    protected array $meta = [];

    public function addMetadata(string $key, mixed $value): self
    {
        $this->meta[$key] = $value;
        return $this;
    }

    public function getMetadata(string $key): mixed
    {
        return $this->meta[$key] ?? null;
    }

    public function setMetadata(array $meta): self
    {
        $this->meta = $meta;
        return $this;
    }
}
