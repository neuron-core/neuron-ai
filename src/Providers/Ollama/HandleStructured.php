<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Ollama;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;

use function array_merge;
use function is_array;

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

        $response = $this->chat(...(is_array($messages) ? $messages : [$messages]));

        // Remove the structured output parameters to not affect subsequent requests with different methods, like chat or stream.
        unset($this->parameters['format']);

        return $response;
    }
}
