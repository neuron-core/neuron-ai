<?php

declare(strict_types=1);

namespace NeuronAI\Chat\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class TextContentBlock implements ContentBlock
{
    public function __construct(
        public readonly string $text
    ) {}

    public function getType(): ContentBlockType
    {
        return ContentBlockType::TEXT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType()->value,
            'text' => $this->text,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
