<?php

declare(strict_types=1);

namespace NeuronAI\Providers\Anthropic;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\Exceptions\ProviderException;
use NeuronAI\HandleContent;

use function json_encode;
use function is_array;

use const PHP_EOL;

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
        array $response_format
    ): Message {
        $this->system .= PHP_EOL."<output-constraint>".PHP_EOL.
            "Your response must be a JSON string following this schema: ".PHP_EOL.
            json_encode($response_format) . PHP_EOL. "</output-constraint>";

        $response = $this->chat(...(is_array($messages) ? $messages : [$messages]));

        // Remove the structured output parameters to not affect subsequent requests with different methods, like chat or stream.
        $this->system = $this->removeDelimitedContent($this->system, '<output-constraint>', '</output-constraint>');

        return $response;
    }
}
