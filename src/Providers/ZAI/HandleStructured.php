<?php

declare(strict_types=1);

namespace NeuronAI\Providers\ZAI;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;

use function array_replace_recursive;
use function is_array;
use function json_encode;

use const JSON_PRETTY_PRINT;

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
            'response_format' => ['type' => 'json_object'],
        ]);

        $this->system .= "\n\n---\n\nGenerate a JSON with the following schema: \n\n".json_encode($response_format, JSON_PRETTY_PRINT);

        return $this->chat(...(is_array($messages) ? $messages : [$messages]));
    }
}
