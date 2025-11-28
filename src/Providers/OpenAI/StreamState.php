<?php

declare(strict_types=1);

namespace NeuronAI\Providers\OpenAI;

use NeuronAI\Chat\Messages\ContentBlocks\ContentBlockInterface;
use NeuronAI\Providers\BasicStreamState;

use function array_key_exists;

class StreamState extends BasicStreamState
{
    public function updateContentBlock(int $index, ContentBlockInterface $block): void
    {
        if (!isset($this->blocks[$index])) {
            $this->blocks[$index] = $block;
        } else {
            $this->blocks[$index]->accumulateContent($block->getContent());
        }
    }

    public function composeToolCalls(array $event): void
    {
        foreach ($event['choices'][0]['delta']['tool_calls'] as $call) {
            $index = $call['index'];

            if (!array_key_exists($index, $this->toolCalls)) {
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
