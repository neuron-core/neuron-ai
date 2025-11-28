<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

abstract class ContentBlock implements ContentBlockInterface
{
    public function __construct(public string $content)
    {
    }

    public function accumulateContent(string $content): void
    {
        $this->content .= $content;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
