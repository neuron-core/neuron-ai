<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class ReasoningContent extends TextContent
{
    public function __construct(
        public string $text,
        public ?string $id = null,
    ) {
        parent::__construct($text);
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::REASONING;
    }

    public function toArray(): array
    {
        return \array_merge(parent::toArray(), ['id' => $this->id]);
    }
}
