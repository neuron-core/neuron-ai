<?php

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
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
            'response_format' => 'json_object'
        ]);

        $messages = is_array($messages) ? $messages : [$messages];

        $last = end($messages);
        $last->addContent(new TextContent(
            "Generate a JSON with the following schema: \n\n".json_encode($response_format, JSON_PRETTY_PRINT)
        ));

        return $this->chat($messages);
    }
}
