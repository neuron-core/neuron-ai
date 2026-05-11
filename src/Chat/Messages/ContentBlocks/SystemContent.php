<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use Stringable;

class SystemContent extends ContentBlock implements Stringable
{
    protected bool $cached = false;

    public function getType(): ContentBlockType
    {
        return ContentBlockType::SYSTEM;
    }

    public function cache(): static
    {
        $this->cached = true;
        return $this;
    }

    public function isCached(): bool
    {
        return $this->cached;
    }

    public function __toString(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'content' => $this->content,
            'meta' => $this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
