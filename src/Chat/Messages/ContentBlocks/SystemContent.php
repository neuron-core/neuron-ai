<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use Stringable;

class SystemContent extends TextContent implements Stringable
{
    public function __construct(string $content, protected bool $cached = false)
    {
        parent::__construct($content);
    }

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
