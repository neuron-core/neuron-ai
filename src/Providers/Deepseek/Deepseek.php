<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Deepseek;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\OpenAI\OpenAI;

class Deepseek extends OpenAI
{
    protected string $baseUri = "https://api.deepseek.com/v1";

    /**
     * @param array<string, mixed> $response_format
     */
    public function structured(
        array $messages,
        string $class,
        array $response_format,
        bool $strict = false,
    ): Message {
        $this->parameters = \array_merge($this->parameters, [
            'response_format' => [
                'type' => 'json_object',
            ]
        ]);

        $this->system .= \PHP_EOL."# OUTPUT FORMAT CONSTRAINTS".\PHP_EOL
            .'Generate a json respecting this schema: '.\json_encode($response_format);

        return $this->chat($messages);
    }
}
