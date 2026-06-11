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
        $originalSystem = $this->system;

        try {
            $this->system .= PHP_EOL."<output-contraints>".PHP_EOL.
                "Your response should be a JSON string following this schema: ".PHP_EOL.
                json_encode($response_format). PHP_EOL. "</output-contraints>";

            return $this->chat(...(is_array($messages) ? $messages : [$messages]));
        } finally {
            $this->system = $originalSystem;
        }
    }
}
