<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlock;
use NeuronAI\Chat\Messages\ContentBlocks\ReasoningContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\UniqueIdGenerator;

class StreamState
{
    protected string $messageId;

    public function __construct(
        protected Usage $usage = new Usage(0, 0),
        public string $text = '',
        public string $reasoning = '',
    ) {
    }

    public function messageId(): string
    {
        if (!isset($this->messageId)) {
            $this->messageId = UniqueIdGenerator::generateId('msg_');
        }

        return $this->messageId;
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

    /**
     * @return ContentBlock[]
     */
    public function getContentBlocks(): array
    {
        $blocks = [];

        if ($this->text !== '') {
            $blocks[] = new TextContent($this->text);
        }

        if ($this->reasoning !== '') {
            $blocks[] = new ReasoningContent($this->reasoning);
        }

        return $blocks;
    }
}
