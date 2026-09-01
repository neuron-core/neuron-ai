<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Providers\BasicStreamState;

class StreamState extends BasicStreamState
{
    public function updateContentBlock(int $index, ContentBlockInterface $block): void
    {
        if (!isset($this->blocks[$index])) {
            $this->blocks[$index] = $block;
        } else {
            $this->blocks[$index]->accumulateContent($block->getContent());
        }
    }

    public function signReasoningContentBlock(int $index, string $signature): void
    {
        $block = $this->blocks[$index] ?? null;

        if ($block instanceof ReasoningContent) {
            $block->id = $signature;
        }
    }
}
