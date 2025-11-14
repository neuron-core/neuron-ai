<?php

namespace NeuronAI\Providers\Gemini;

use NeuronAI\Chat\Messages\Usage;
use NeuronAI\UniqueIdGenerator;

class StreamState
{
    protected string $messageId;

    protected array $toolCalls = [];

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
            $this->messageId = UniqueIdGenerator::generateId('gemini_');
        }

        return $this->messageId;
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
