<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Providers\BasicStreamState;

class StreamState extends BasicStreamState
{
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
}
