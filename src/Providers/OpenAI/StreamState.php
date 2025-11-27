<?php

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Providers\BasicStreamState;

class StreamState extends BasicStreamState
{
    public function updateContentBlock(int $index, string $content): void
    {
        if (!isset($this->blocks[$index])) {
            $this->blocks[$index] = new TextContent('');
        }

        $this->blocks[$index]->accumulateContent($content);
    }

    public function composeToolCalls(array $event): void
    {
        foreach ($event['choices'][0]['delta']['tool_calls'] as $call) {
            $index = $call['index'];

            if (!\array_key_exists($index, $this->toolCalls)) {
                if ($name = $call['function']['name'] ?? null) {
                    $this->toolCalls[$index]['function'] = ['name' => $name, 'arguments' => $call['function']['arguments'] ?? ''];
                    $this->toolCalls[$index]['id'] = $call['id'];
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
