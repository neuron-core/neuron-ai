<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HandleContent;

use function array_replace_recursive;
use function is_array;
use function json_encode;

use const JSON_PRETTY_PRINT;

trait HandleStructured
{
    use HandleContent;

    /**
     * @throws ProviderException
     * @throws HttpException
     */
    public function structured(
        array|Message $messages,
        string $class,
        array $response_format,
    ): Message {
        $originalParameters = $this->parameters;
        $originalSystem = $this->system;

        try {
            $this->parameters = array_replace_recursive($this->parameters, [
                'response_format' => ['type' => 'json_object'],
            ]);

            $this->system .= "\n\n<outout-constraints>Generate a JSON with the following schema: \n\n".json_encode($response_format, JSON_PRETTY_PRINT)."</outout-constraints>";

            return $this->chat(...(is_array($messages) ? $messages : [$messages]));
        } finally {
            $this->parameters = $originalParameters;
            $this->system = $originalSystem;
        }
    }
}
