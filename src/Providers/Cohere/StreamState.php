<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Providers\OpenAI\StreamState as OpenAIStreamState;

use function array_key_exists;
use function array_values;

class StreamState extends OpenAIStreamState
{
    public function composeToolCalls(array $event): void
    {
        $index = $event['index'];
        $call = $event['delta']['message']['tool_calls'];

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

    public function getToolCalls(): array
    {
        return array_values($this->toolCalls);
    }
}
