<?php

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

abstract class ContentBlock implements ContentBlockInterface
{
    public function __construct(public string $content) {}

    public function accumulateContent(string $content): void
    {
        $this->content .= $content;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
