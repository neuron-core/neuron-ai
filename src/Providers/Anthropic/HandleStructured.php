<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;

use function json_encode;

use const PHP_EOL;

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
        $this->system .= PHP_EOL."# OUTPUT CONSTRAINTS".PHP_EOL.
            "Your response must be a JSON string following this schema: ".PHP_EOL.
            json_encode($response_format);

        return $this->chat($messages);
    }
}
