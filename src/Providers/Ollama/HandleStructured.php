<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;

use function array_merge;

trait HandleStructured
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format
    ): Message {
        $this->parameters = array_merge($this->parameters, [
            'format' => $response_format,
        ]);

        return $this->chat($messages);
    }
}
