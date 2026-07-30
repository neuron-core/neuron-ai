<?php

declare(strict_types=1);

namespace NeuronAI\Observability\Events;

use NeuronAI\Observability\ObservabilityEvent;
use NeuronAI\Chat\Messages\Message;

class Extracted extends ObservabilityEvent
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(public Message $message, public array $schema, public ?string $json)
    {
    }

    public function name(): string
    {
        return 'structured-extracted';
    }
}
