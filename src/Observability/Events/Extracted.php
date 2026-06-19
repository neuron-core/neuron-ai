<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Chat\Messages\Message;

class Extracted
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public Message $message, public array $schema, public ?string $json)
    {
    }
}
