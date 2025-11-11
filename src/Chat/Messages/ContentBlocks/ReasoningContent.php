<?php

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class ReasoningContent extends TextContent
{
    public function __construct(
        string $text,
        public readonly ?string $id = null,
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
