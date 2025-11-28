<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

use function array_merge;

class ReasoningContent extends TextContent
{
    public function __construct(
        string $content,
        public ?string $id = null,
    ) {
        parent::__construct($content);
    }

    public function getType(): ContentBlockType
    {
        return ContentBlockType::REASONING;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), ['id' => $this->id]);
    }
}
