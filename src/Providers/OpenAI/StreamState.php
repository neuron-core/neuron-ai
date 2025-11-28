<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Providers\BasicStreamState;

class StreamState extends BasicStreamState
{
    /**
     * Recreate the tool_calls format from streaming OpenAI Chat Completions API.
     *
     * @param array<string, mixed> $line
     */

    public function composeToolCalls(array $line): void
    {
        if (empty($line['choices'][0]['delta']['tool_calls'])) {
            return;
        }

        foreach ($line['choices'][0]['delta']['tool_calls'] as $call) {
            $index = $call['index'];

            if (!\array_key_exists($index, $this->toolCalls)) {
                if ($name = $call['function']['name'] ?? null) {
                    $this->toolCalls[$index]['function'] = [
                        'name' => $name,
                        'arguments' => $call['function']['arguments'] ?? '',
                    ];
                    $this->toolCalls[$index]['id'] = $call['id'] ?? null;
                    $this->toolCalls[$index]['type'] = 'function';
                }
            } else {
                $arguments = $call['function']['arguments'] ?? null;
                if ($arguments !== null) {
                    $this->toolCalls[$index]['function']['arguments'] .= $arguments;
                }
            }
        }
    }
}
