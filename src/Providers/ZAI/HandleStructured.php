<?php

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;

trait HandleStructured
{
    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format,
    ): Message {
        $this->parameters = array_replace_recursive($this->parameters, [
            'response_format' => [
                'type' => 'json_object',
                'json_object' => $response_format,
            ]
        ]);

        return $this->chat(...(is_array($messages) ? $messages : [$messages]));
    }
}
