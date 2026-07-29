<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\ContentBlocks\SystemContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\Providers\ProviderResponse;

use function json_encode;
use function is_array;

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
    ): ProviderResponse {
        $originalSystem = $this->system;

        try {
            $schemaText = "# OUTPUT CONSTRAINTS".PHP_EOL.
                "Your response must be a JSON string following this schema: ".PHP_EOL.
                json_encode($response_format);

            // Append the schema as a trailing block to keep cached blocks untouched.
            $blocks = $this->system instanceof SystemMessage ? $this->system->getContentBlocks() : [];
            $this->system = new SystemMessage([...$blocks, new SystemContent($schemaText)]);

            return $this->chat(...(is_array($messages) ? $messages : [$messages]));
        } finally {
            $this->system = $originalSystem;
        }
    }
}
