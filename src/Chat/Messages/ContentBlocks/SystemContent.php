<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use Stringable;

class SystemContent extends TextContent implements Stringable
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
}
