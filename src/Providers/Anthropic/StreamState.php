<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Usage;

class StreamState
{
    public string $messageId;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $toolCalls = [];

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

    /**
     * Recreate the tool_call format of anthropic API from streaming.
     *
     * @param  array<string, mixed>  $line
     */
    public function composeToolCalls(array $line): void
    {
        if (!\array_key_exists($line['index'], $this->toolCalls)) {
            $this->toolCalls[$line['index']] = [
                'type' => 'tool_use',
                'id' => $line['content_block']['id'],
                'name' => $line['content_block']['name'],
                'input' => '',
            ];
        } elseif ($input = $line['delta']['partial_json'] ?? null) {
            $this->toolCalls[$line['index']]['input'] .= $input;
        }
    }

    public function getToolCalls(): array
    {
        // Decode the input and return
        return \array_map(function (array $call): array {
            $call['input'] = \json_decode((string) $call['input'], true);
            return $call;
        }, $this->toolCalls);
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
