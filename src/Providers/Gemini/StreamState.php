<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\UniqueIdGenerator;

class StreamState
{
    protected string $messageId;

    protected array $toolCalls = [];

    /**
     * @var array<string, ContentBlock>
     */
    protected array $blocks = [];

    public function __construct(
        protected Usage $usage = new Usage(0, 0),
    ) {
    }

    public function addInputTokens(int $tokens): self
    {
        $this->usage->inputTokens += $tokens;
        return $this;
    }

    public function addOutputTokens(int $tokens): self
    {
        $this->usage->outputTokens += $tokens;
        return $this;
    }

    public function getUsage(): Usage
    {
        return $this->usage;
    }

    public function messageId(): string
    {
        if (!isset($this->messageId)) {
            $this->messageId = UniqueIdGenerator::generateId('msg_');
        }

        return $this->messageId;
    }

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
     * @return ContentBlock[]
     */
    public function getContentBlocks(): array
    {
        return \array_values($this->blocks);
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

    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }
}
