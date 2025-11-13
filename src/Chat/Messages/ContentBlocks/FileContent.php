<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;
use NeuronAI\Chat\Enums\SourceType;

class FileContent implements ContentBlock
{
    public function __construct(
        public readonly string $source,
        public readonly SourceType $sourceType,
        public readonly ?string $mediaType = null,
        public readonly ?string $filename = null
    ) {
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::FILE;
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
            'filename' => $this->filename,
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
