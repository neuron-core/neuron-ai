<?php

declare(strict_types=1);

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class RedactedReasoningContent extends ContentBlock
{
    public function getType(): ContentBlockType
    {
        return ContentBlockType::REDACTED_REASONING;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'content' => $this->content,
            'meta' => $this->meta,
        ];
    }
}
