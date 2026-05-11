<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Message;
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
        $schemaText = "# OUTPUT CONSTRAINTS".PHP_EOL.
            "Your response must be a JSON string following this schema: ".PHP_EOL.
            json_encode($response_format);

        if (isset($this->systemBlocks)) {
            $this->systemBlocks[] = [
                'type' => 'text',
                'text' => $schemaText,
            ];
        } else {
            $this->system .= PHP_EOL.$schemaText;
        }

        return $this->chat(...(is_array($messages) ? $messages : [$messages]));
    }
}
