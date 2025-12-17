<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Providers\OpenAI\MessageMapper as OpenAIMessageMapper;

class MessageMapper extends OpenAIMessageMapper
{
    protected function mapContentBlock(ContentBlockInterface $block): ?array
    {
        return match ($block::class) {
            FileContent::class => null,
            ReasoningContent::class => [
                'type' => 'thinking',
                'thinking' => $block->content,
            ],
            default => parent::mapContentBlock($block),
        };
    }
}
