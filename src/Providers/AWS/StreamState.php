<?php

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Providers\BasicStreamState;

class StreamState extends BasicStreamState
{
    public function updateContentBlock(int $index, ContentBlockInterface $block): void
    {
        if (!isset($this->blocks[$index])) {
            $this->blocks[$index] = $block;
        } else {
            $this->blocks[$index]->accumulateContent($block->content);
        }
    }
}
