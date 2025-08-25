<?php

declare(strict_types=1);

namespace NeuronAI\Providers\AWS;

use NeuronAI\Chat\Messages\Message;

trait HandleStructured
{
    public function structured(
        array $messages,
        string $class,
        array $response_format
    ): Message {
        $this->system .= \PHP_EOL."# OUTPUT CONSTRAINTS".\PHP_EOL.
            "Your response should be a JSON string following this schema: ".\PHP_EOL.
            \json_encode($response_format);

        return $this->chat($messages);
    }
}
