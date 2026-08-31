<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Providers\BasicStreamState;

class StreamState extends BasicStreamState
{
    public function addContentBlock(string $type, ContentBlockInterface $block): void
    {
        $this->blocks[$type] = $block;
    }

    public function updateContentBlock(string $type, string $content): void
    {
        $this->blocks[$type] ??= $type === 'text' ? new TextContent('') : new ReasoningContent('');

        $this->blocks[$type]->accumulateContent($content);
    }

    /**
     * Recreate the tool_calls format from streaming Gemini API.
     *
     * Function calls arrive as complete parts but may be split across stream
     * chunks (each at part index 0), so calls are accumulated by appending —
     * keying by the per-chunk part index would overwrite earlier calls.
     */
    public function composeToolCalls(array $event): void
    {
        $parts = $event['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $call = ['functionCall' => $part['functionCall']];

                if ($this->toolCalls === [] && $signature = $part['thoughtSignature'] ?? null) {
                    $call['thoughtSignature'] = $signature;
                }

                $this->toolCalls[] = $call;
            }
        }
    }
}
