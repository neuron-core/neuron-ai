<?php

declare(strict_types=1);

namespace NeuronAI\Chat\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\SourceType;

class ImageContentBlock implements ContentBlock
{
    public function __construct(
        public readonly string $source,
        public readonly SourceType $sourceType,
        public readonly ?string $mediaType = null
    ) {
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::IMAGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return \array_filter([
            'type' => $this->getType()->value,
            'source' => $this->source,
            'source_type' => $this->sourceType->value,
            'media_type' => $this->mediaType,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
