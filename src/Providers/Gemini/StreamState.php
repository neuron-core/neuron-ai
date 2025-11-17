<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Providers\BasicStreamState;
use NeuronAI\UniqueIdGenerator;

class StreamState extends BasicStreamState
{
    public function addContentBlock(string $type, ContentBlock $block): void
    {
        $this->blocks[$type] = $block;
    }

    public function updateContentBlock(string $type, string $content): void
    {
        if (!isset($this->blocks[$type])) {
            $this->blocks[$type] = $type === 'text' ? new TextContent('') : new ReasoningContent('');
        }

        $this->blocks[$type]->text .= $content;
    }

    /**
     * Recreate the tool_calls format from streaming Gemini API.
     */
    public function composeToolCalls(array $event): void
    {
        $parts = $event['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $index => $part) {
            if (isset($part['functionCall'])) {
                $this->toolCalls[$index]['functionCall'] = $part['functionCall'];
            }
        }
    }
}
