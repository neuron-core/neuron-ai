<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Messages\Message;

use function array_merge;

trait HandleStructured
{
    public function structured(
        array $messages,
        string $class,
        array $response_format
    ): Message {
        $this->parameters = array_merge($this->parameters, [
            'format' => $response_format,
        ]);

        return $this->chat($messages);
    }
}
