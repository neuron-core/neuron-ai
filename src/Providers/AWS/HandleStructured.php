<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\HandleContent;

use function json_encode;
use function is_array;

use const PHP_EOL;

trait HandleStructured
{
    use HandleContent;

    public function structured(
        array|Message $messages,
        string $class,
        array $response_format
    ): Message {
        $this->system .= PHP_EOL."<output-contraints>".PHP_EOL.
            "Your response should be a JSON string following this schema: ".PHP_EOL.
            json_encode($response_format). PHP_EOL. "</output-contraints>";

        $response = $this->chat(...(is_array($messages) ? $messages : [$messages]));

        // Remove the structured output parameters to not affect subsequent requests with different methods, like chat or stream.
        $this->system = $this->removeDelimitedContent($this->system, '<output-contraints>', '</output-contraints>');

        return $response;
    }
}
