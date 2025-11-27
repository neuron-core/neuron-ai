<?php

declare(strict_types=1);

namespace NeuronAI\Providers;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\UniqueIdGenerator;

class BasicStreamState
{
    protected string $messageId;

    protected array $toolCalls = [];

    /**
     * @var array<string|int, ContentBlockInterface>
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

    public function messageId(?string $id = null): string
    {
        if ($id !== null) {
            $this->messageId = $id;
        }

        if (!isset($this->messageId)) {
            $this->messageId = UniqueIdGenerator::generateId('msg_');
        }

        return $this->messageId;
    }

    /**
     * @return ContentBlockInterface[]
     */
    public function getContentBlocks(): array
    {
        return \array_values($this->blocks);
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }
}
