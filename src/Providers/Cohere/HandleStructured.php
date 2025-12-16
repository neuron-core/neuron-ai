<?php

namespace NeuronAI\Providers\Cohere;

use NeuronAI\Chat\Messages\Message;

trait HandleStructured
{
    public function structured(
        array $messages,
        string $class,
        array $response_format
    ): Message {

    }
}
