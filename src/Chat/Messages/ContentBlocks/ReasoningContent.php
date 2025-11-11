<?php

namespace NeuronAI\Chat\Messages\ContentBlocks;

use NeuronAI\Chat\Enums\ContentBlockType;

class ReasoningContent extends TextContent
{
    public function getType(): ContentBlockType
    {
        return ContentBlockType::REASONING;
    }
}
