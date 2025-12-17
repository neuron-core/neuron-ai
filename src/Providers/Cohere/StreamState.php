<?php

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Providers\OpenAI\StreamState as OpenAIStreamState;

class StreamState extends OpenAIStreamState
{
    public function composeToolCalls(array $event): void
    {
        foreach ($event['delta']['message']['tool_calls'] as $index => $call) {
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
